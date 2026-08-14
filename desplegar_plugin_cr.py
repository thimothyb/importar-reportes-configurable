#!/usr/bin/env python3
"""
Despliegue del PLUGIN propio "Configurable Reports" (block_configurable_reports,
por SSH).

PASO 2 del flujo de migración. Es un plugin DISTINTO del alquilado ("Informes
Avanzados", block_advanced_reports): no comparten component ni tablas, así que
esto es una instalación nueva en blocks/configurable_reports, no toca en
absoluto blocks/advanced_reports.

Por cada servidor:
  1. Backup: si por algún motivo ya existía algo en blocks/configurable_reports
     (p.ej. una migración previa), lo empaqueta en .tar.gz y lo descarga a
     ./backups/<servidor>/<timestamp>.tar.gz antes de sustituirlo.
  2. Sube el código propio a una carpeta temporal remota.
  3. Sustituye blocks/configurable_reports por el código nuevo y ajusta
     permisos (chown web_user:web_group).
  4. Purga cachés y corre admin/cli/upgrade.php --non-interactive como
     usuario web, para que Moodle registre la instalación.

No borra ni toca nada del plugin alquilado (código ni tablas): eso, si se
quiere, lo hace desinstalar_plugin_alquilado_cr.py (PASO 4, opcional). No
toca la tabla block_configurable_reports: eso lo hace instalar_plantillas_cr.py
(modo migración) en el PASO 3.
"""

from __future__ import annotations

import posixpath
import sys
import tempfile
import uuid
import zipfile
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import TYPE_CHECKING, Any, Dict, FrozenSet, List, Optional, Sequence

if TYPE_CHECKING:
    import paramiko
from rich.console import Console
from rich.table import Table

from cr_common import (
    CommandLog,
    RemoteCommandError,
    apply_execution_mode,
    backup_remote_dir,
    connect_ssh,
    execute_remote,
    is_local_mode,
    load_inventory,
    logger,
    prompt_execution_mode,
    prompt_server_selection,
    q,
    run_remote_command,
    safe_cleanup,
    safe_text,
)

SCRIPT_DIR = Path(__file__).resolve().parent
INVENTORY_FILE = SCRIPT_DIR / "inventario.json"
BACKUPS_DIR = SCRIPT_DIR / "backups"
REMOTE_TMP_DIR = "/tmp"
PLUGIN_BLOCK_NAME = "configurable_reports"
EXCLUDE_ENTRIES: FrozenSet[str] = frozenset({".git", ".github", ".claude", "__pycache__", ".DS_Store"})

console = Console()


@dataclass
class DeployResult:
    server_name: str
    success: bool = False
    fatal: str = ""
    backup_path: Optional[str] = None
    had_previous_plugin: bool = False
    command_logs: List[CommandLog] = field(default_factory=list)


# --- Subida recursiva por SFTP ----------------------------------------------
def sftp_mkdir_if_missing(sftp: "paramiko.SFTPClient", remote_dir: str) -> None:
    """Crea directorio remoto si no existe (patrón EAFP, sin race condition)."""
    try:
        sftp.mkdir(remote_dir)
    except IOError:
        # Ya existe — verificar que realmente es un directorio.
        pass


def recursive_put(sftp: "paramiko.SFTPClient", local_dir: Path, remote_dir: str) -> int:
    """Sube local_dir → remote_dir recursivamente, saltando EXCLUDE_ENTRIES. Devuelve nº de archivos subidos."""
    sftp_mkdir_if_missing(sftp, remote_dir)
    count = 0
    for entry in sorted(local_dir.iterdir()):
        if entry.name in EXCLUDE_ENTRIES:
            continue
        remote_path = posixpath.join(remote_dir, entry.name)
        if entry.is_dir():
            count += recursive_put(sftp, entry, remote_path)
        elif entry.is_file():
            sftp.put(str(entry), remote_path)
            count += 1
    return count


# --- Validación del origen ---------------------------------------------------
def validate_plugin_source(source_dir: Path) -> None:
    version_file = source_dir / "version.php"
    if not version_file.is_file():
        raise FileNotFoundError(
            f"«{source_dir}» no parece la raíz del plugin: falta version.php. "
            "Debe apuntar a la carpeta que contiene version.php, block_configurable_reports.php, etc."
        )
    content = version_file.read_text(encoding="utf-8", errors="ignore")
    if "block_configurable_reports" not in content:
        console.print(
            "[yellow]Aviso: version.php no menciona el component 'block_configurable_reports'. "
            "Verifica que es el plugin correcto.[/yellow]"
        )


def resolve_plugin_source(source_path: Path) -> tuple[Path, Optional[tempfile.TemporaryDirectory]]:
    """Acepta una carpeta o un .zip. Si es zip, lo descomprime en un dir temporal
    y devuelve la carpeta del plugin dentro. Retorna (source_dir, tmp_dir_handle).
    El llamante debe conservar tmp_dir_handle vivo hasta terminar de usarlo."""
    if source_path.is_dir():
        return source_path, None

    if source_path.suffix.lower() == ".zip" and source_path.is_file():
        console.print(f"[cyan]Descomprimiendo {source_path.name}...[/cyan]")
        tmp = tempfile.TemporaryDirectory(prefix="cr_deploy_zip_")
        tmp_path = Path(tmp.name)
        with zipfile.ZipFile(source_path, "r") as zf:
            zf.extractall(tmp_path)
        # Buscar la carpeta que contiene version.php
        candidates = list(tmp_path.rglob("version.php"))
        for c in candidates:
            content = c.read_text(encoding="utf-8", errors="ignore")
            if "block_configurable_reports" in content:
                console.print(f"[green]Plugin encontrado en: {c.parent.name}[/green]")
                return c.parent, tmp
        # Fallback: primer version.php encontrado
        if candidates:
            return candidates[0].parent, tmp
        tmp.cleanup()
        raise FileNotFoundError(
            f"El zip «{source_path.name}» no contiene version.php. "
            "Verifica que el zip tiene el código del plugin."
        )

    raise FileNotFoundError(
        f"«{source_path}» no es una carpeta ni un archivo .zip válido."
    )


# --- Flujo por servidor -------------------------------------------------------
def deploy_to_server(server: Dict[str, Any], source_dir: Path) -> DeployResult:
    result = DeployResult(server_name=server["name"])

    moodle_path = str(server["moodle_path"]).rstrip("/")
    target_dir = posixpath.join(moodle_path, "blocks", PLUGIN_BLOCK_NAME)
    web_user = str(server["web_user"])
    web_group = str(server.get("web_group") or web_user)
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_staging = posixpath.join(REMOTE_TMP_DIR, f"cr_deploy_{token}")
    remote_staging_plugin = posixpath.join(remote_staging, PLUGIN_BLOCK_NAME)

    ssh = None
    sftp = None
    cleanup_errors: List[str] = []

    try:
        conn_label = "localmente" if is_local_mode(server) else "por SSH"
        console.print(f"[cyan]→ {result.server_name}: conectando {conn_label}...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        # Paso 0: ¿existe ya un plugin instalado?
        check_status, _o, _e = run_remote_command(ssh, f"test -d {q(target_dir)}")
        result.had_previous_plugin = (check_status == 0)

        # Paso 1: backup (si ya había algo en blocks/configurable_reports).
        if result.had_previous_plugin:
            console.print(f"[cyan]→ {result.server_name}: Paso 1/4 (backup de lo existente)[/cyan]")
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            local_backup = BACKUPS_DIR / result.server_name / f"configurable_reports_{timestamp}.tar.gz"
            backup_cleanup_errors = backup_remote_dir(
                result.command_logs, ssh, sftp,
                remote_parent_dir=posixpath.join(moodle_path, "blocks"),
                dir_name=PLUGIN_BLOCK_NAME,
                local_backup_path=local_backup,
                sudo_password=sudo_password,
            )
            cleanup_errors.extend(backup_cleanup_errors)
            result.backup_path = str(local_backup)
        else:
            console.print(f"[yellow]→ {result.server_name}: no había plugin previo en {target_dir}; sin backup.[/yellow]")

        # Paso 2: subir código nuevo a staging.
        console.print(f"[cyan]→ {result.server_name}: Paso 2/4 (subiendo código propio)[/cyan]")
        execute_remote(result.command_logs, ssh, step="Paso 2 - Crear staging",
                        command=f"mkdir -p {q(remote_staging)} && chmod 755 {q(remote_staging)}",
                        sudo_password=sudo_password)
        uploaded = recursive_put(sftp, source_dir, remote_staging_plugin)
        console.print(f"   [dim]{uploaded} archivo(s) subidos.[/dim]")

        # Paso 3: sustituir el directorio del plugin y ajustar permisos.
        # Reemplazo atómico: renombrar el viejo antes de mover el nuevo,
        # así si el mv falla el original sigue disponible.
        console.print(f"[cyan]→ {result.server_name}: Paso 3/4 (sustituyendo código + permisos)[/cyan]")
        old_backup_dir = f"{target_dir}.__old_{token}"
        if result.had_previous_plugin:
            execute_remote(
                result.command_logs, ssh, step="Paso 3 - Renombrar plugin existente",
                command=f"sudo mv {q(target_dir)} {q(old_backup_dir)}",
                sudo_password=sudo_password,
            )
        execute_remote(
            result.command_logs, ssh, step="Paso 3 - Mover código nuevo",
            command=f"sudo mv {q(remote_staging_plugin)} {q(target_dir)}",
            sudo_password=sudo_password,
        )
        # Solo borrar el viejo después de que el nuevo está en su lugar.
        if result.had_previous_plugin:
            safe_cleanup(ssh, f"sudo rm -rf {q(old_backup_dir)}", cleanup_errors,
                         sudo_password=sudo_password)
        execute_remote(
            result.command_logs, ssh, step="Paso 3 - chown",
            command=f"sudo chown -R {q(web_user)}:{q(web_group)} {q(target_dir)}",
            sudo_password=sudo_password,
        )

        # Paso 4: purgar cachés y correr upgrade.php como usuario web.
        console.print(f"[cyan]→ {result.server_name}: Paso 4/4 (purge_caches + upgrade.php)[/cyan]")
        execute_remote(
            result.command_logs, ssh, step="Paso 4 - purge_caches",
            command=f"sudo -u {q(web_user)} env HOME=/tmp php {q(posixpath.join(moodle_path, 'admin/cli/purge_caches.php'))}",
            sudo_password=sudo_password, timeout=600,
        )
        execute_remote(
            result.command_logs, ssh, step="Paso 4 - upgrade.php",
            command=f"sudo -u {q(web_user)} env HOME=/tmp php {q(posixpath.join(moodle_path, 'admin/cli/upgrade.php'))} --non-interactive",
            sudo_password=sudo_password, timeout=1800,
        )

        result.success = True
        logger.info("Deploy OK en %s", result.server_name)

    except RemoteCommandError as exc:
        result.fatal = f"{exc.log.step}: " + (exc.log.stderr.rstrip("\n") or exc.log.stdout.rstrip("\n") or str(exc))
        logger.error("Deploy FALLÓ en %s: %s", result.server_name, result.fatal)
    except Exception as exc:  # noqa: BLE001
        result.fatal = str(exc)
        logger.error("Deploy EXCEPCIÓN en %s: %s", result.server_name, result.fatal)
    finally:
        if ssh is not None:
            safe_cleanup(ssh, f"sudo rm -rf {q(remote_staging)}", cleanup_errors,
                         sudo_password=sudo_password)
        if sftp is not None:
            try:
                sftp.close()
            except Exception:  # noqa: BLE001
                pass
        if ssh is not None:
            try:
                ssh.close()
            except Exception:  # noqa: BLE001
                pass
        if cleanup_errors and not result.fatal:
            console.print(f"[yellow]Limpieza con incidencias en {result.server_name}: {' | '.join(cleanup_errors)}[/yellow]")

    return result


def print_summary(results: Sequence[DeployResult]) -> None:
    table = Table(title="Despliegue del plugin propio")
    table.add_column("Servidor")
    table.add_column("Resultado", justify="center")
    table.add_column("Backup previo")
    table.add_column("Detalle")

    for r in results:
        estado = "[green]OK[/green]" if r.success else "[red]FALLÓ[/red]"
        backup = r.backup_path or ("sin plugin previo" if not r.had_previous_plugin else "—")
        table.add_row(r.server_name, estado, backup, r.fatal or "Completado")

    console.print()
    console.print(table)


def main() -> None:
    console.print("[bold cyan]Despliegue del plugin propio · Configurable Reports[/bold cyan]\n")
    try:
        servers, settings = load_inventory(INVENTORY_FILE)
        mode = prompt_execution_mode()
        apply_execution_mode(servers, mode)

        default_source = settings.get("plugin_source_dir")
        if not default_source:
            # Buscar .zip del plugin en la carpeta del script
            zips = sorted(SCRIPT_DIR.glob("configurable_reports*.zip"), reverse=True)
            if zips:
                default_source = str(zips[0])
            else:
                guess = SCRIPT_DIR.parent / "moodle-plugin-reporte-configurable" / "configurable_reports"
                default_source = str(guess) if guess.is_dir() else ""

        source_answer = safe_text(
            "Carpeta o ZIP del plugin propio (la que contiene version.php):",
            default=default_source,
        )
        source_path = Path(source_answer.strip().strip('"').strip("'")).expanduser()
        source_dir, tmp_dir_handle = resolve_plugin_source(source_path)
        validate_plugin_source(source_dir)
        console.print(f"[green]Origen validado:[/green] {source_dir}")

        selected_servers = prompt_server_selection(servers)
        if not selected_servers:
            console.print("[yellow]No se seleccionaron plataformas. Operación cancelada.[/yellow]")
            return

        console.print(
            "\n[bold]Esto sustituirá el código de blocks/configurable_reports y correrá upgrade.php en:[/bold]"
        )
        for s in selected_servers:
            console.print(f"  • {s['name']} ({s['host']})")

        answer = safe_text(
            "¿Continuar? Se hará backup local antes de sustituir cada plataforma. (y/n)",
            validate=lambda v: True if v and v.strip().lower() in {"y", "n"} else "Responde y o n.",
        )
        if answer.strip().lower() != "y":
            console.print("[yellow]Operación cancelada por el usuario.[/yellow]")
            return

        results = [deploy_to_server(server, source_dir) for server in selected_servers]

        for r in results:
            if r.success:
                console.print(f"[green]✅ {r.server_name}: plugin sustituido y actualizado.[/green]")
            else:
                console.print(f"[red]❌ {r.server_name}: {r.fatal}[/red]")

        print_summary(results)
        console.print(
            "\n[dim]Siguiente paso: ejecuta instalar_plantillas_cr.py en modo migración "
            "para sustituir los reportes de los cursos detectados por auditar_cr.py.[/dim]"
        )

    except KeyboardInterrupt:
        console.print("\n[yellow]Operación cancelada.[/yellow]")
    except Exception as exc:  # noqa: BLE001
        console.print(f"[bold red]Error fatal: {exc}[/bold red]")
        sys.exit(1)
    finally:
        if "tmp_dir_handle" in dir() and tmp_dir_handle is not None:
            tmp_dir_handle.cleanup()


if __name__ == "__main__":
    main()
