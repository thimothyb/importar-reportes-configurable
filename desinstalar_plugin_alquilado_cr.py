#!/usr/bin/env python3
"""
Desinstalación del plugin ALQUILADO "Informes Avanzados" (block_advanced_reports,
por SSH).

PASO 4 del flujo de migración — OPCIONAL y a nivel de PLATAFORMA COMPLETA (no
por curso): borra el código y las tablas de block_advanced_reports en todo el
Moodle. Solo tiene sentido correrlo cuando ya migraste todos los cursos que lo
tenían activo (pasos 1-3) y decidiste dejar de usar/pagar ese plugin.

Es irreversible salvo por el backup que este mismo script hace antes de tocar
nada. Si auditoria_resultado.json (de auditar_cr.py) todavía muestra cursos
con needs_migration=true en una plataforma, se avisa y se pide confirmación
extra antes de continuar con ESA plataforma.

Por cada servidor seleccionado:
  1. Si no hay blocks/advanced_reports, no hace nada (ya estaba fuera).
  2. Backup: empaqueta blocks/advanced_reports en un .tar.gz y lo descarga a
     ./backups/<servidor>/advanced_reports_<timestamp>.tar.gz.
  3. Ejecuta admin/cli/uninstall_plugins.php --plugins=block_advanced_reports
     --run como usuario web (esto borra sus tablas y su registro en Moodle).
  4. Borra la carpeta blocks/advanced_reports del disco.
  5. Purga cachés.
"""

from __future__ import annotations

import json
import posixpath
import sys
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence

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
    safe_text,
)

SCRIPT_DIR = Path(__file__).resolve().parent
INVENTORY_FILE = SCRIPT_DIR / "inventario.json"
AUDIT_SNAPSHOT_FILE = SCRIPT_DIR / "auditoria_resultado.json"
BACKUPS_DIR = SCRIPT_DIR / "backups"
RENTED_COMPONENT = "block_advanced_reports"
RENTED_BLOCK_NAME = "advanced_reports"

console = Console()


@dataclass
class UninstallResult:
    server_name: str
    success: bool = False
    fatal: str = ""
    skipped: bool = False
    backup_path: Optional[str] = None
    command_logs: List[CommandLog] = field(default_factory=list)


def pending_courses_by_server() -> Dict[str, int]:
    """Lee auditoria_resultado.json y cuenta cursos con needs_migration=true por servidor."""
    if not AUDIT_SNAPSHOT_FILE.exists():
        return {}
    try:
        snapshot = json.loads(AUDIT_SNAPSHOT_FILE.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return {}
    pending: Dict[str, int] = {}
    for entry in snapshot.get("servers", []):
        courses = entry.get("courses", []) or []
        count = sum(1 for c in courses if c.get("needs_migration"))
        if count:
            pending[entry.get("name", "")] = count
    return pending


def uninstall_on_server(server: Dict[str, Any]) -> UninstallResult:
    result = UninstallResult(server_name=server["name"])

    moodle_path = str(server["moodle_path"]).rstrip("/")
    target_dir = posixpath.join(moodle_path, "blocks", RENTED_BLOCK_NAME)
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    ssh = None
    sftp = None
    cleanup_errors: List[str] = []

    try:
        conn_label = "localmente" if is_local_mode(server) else "por SSH"
        console.print(f"[cyan]→ {result.server_name}: conectando {conn_label}...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        check_status, _o, _e = run_remote_command(ssh, f"test -d {q(target_dir)}")
        if check_status != 0:
            result.skipped = True
            result.success = True
            console.print(f"[dim]→ {result.server_name}: no hay {target_dir}; nada que desinstalar.[/dim]")
            return result

        console.print(f"[cyan]→ {result.server_name}: Paso 1/3 (backup)[/cyan]")
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        local_backup = BACKUPS_DIR / result.server_name / f"advanced_reports_{timestamp}.tar.gz"
        backup_cleanup_errors = backup_remote_dir(
            result.command_logs, ssh, sftp,
            remote_parent_dir=posixpath.join(moodle_path, "blocks"),
            dir_name=RENTED_BLOCK_NAME,
            local_backup_path=local_backup,
            sudo_password=sudo_password,
        )
        cleanup_errors.extend(backup_cleanup_errors)
        result.backup_path = str(local_backup)

        console.print(f"[cyan]→ {result.server_name}: Paso 2/3 (uninstall_plugins.php)[/cyan]")
        execute_remote(
            result.command_logs, ssh, step="Paso 2 - uninstall_plugins.php",
            command=(
                f"sudo -u {q(web_user)} env HOME=/tmp php "
                f"{q(posixpath.join(moodle_path, 'admin/cli/uninstall_plugins.php'))} "
                f"--plugins={q(RENTED_COMPONENT)} --run"
            ),
            sudo_password=sudo_password, timeout=1800,
        )

        console.print(f"[cyan]→ {result.server_name}: Paso 3/3 (borrar carpeta + purge_caches)[/cyan]")
        execute_remote(
            result.command_logs, ssh, step="Paso 3 - borrar carpeta",
            command=f"sudo rm -rf {q(target_dir)}",
            sudo_password=sudo_password,
        )
        execute_remote(
            result.command_logs, ssh, step="Paso 3 - purge_caches",
            command=f"sudo -u {q(web_user)} env HOME=/tmp php {q(posixpath.join(moodle_path, 'admin/cli/purge_caches.php'))}",
            sudo_password=sudo_password, timeout=600,
        )

        result.success = True
        logger.info("Desinstalación OK en %s", result.server_name)

    except RemoteCommandError as exc:
        result.fatal = f"{exc.log.step}: " + (exc.log.stderr.rstrip("\n") or exc.log.stdout.rstrip("\n") or str(exc))
        logger.error("Desinstalación FALLÓ en %s: %s", result.server_name, result.fatal)
    except Exception as exc:  # noqa: BLE001
        result.fatal = str(exc)
        logger.error("Desinstalación EXCEPCIÓN en %s: %s", result.server_name, result.fatal)
    finally:
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


def print_summary(results: Sequence[UninstallResult]) -> None:
    table = Table(title="Desinstalación del plugin alquilado (block_advanced_reports)")
    table.add_column("Servidor")
    table.add_column("Resultado", justify="center")
    table.add_column("Backup")
    table.add_column("Detalle")

    for r in results:
        if r.skipped:
            estado = "[dim]N/A[/dim]"
        elif r.success:
            estado = "[green]OK[/green]"
        else:
            estado = "[red]FALLÓ[/red]"
        table.add_row(r.server_name, estado, r.backup_path or "—", r.fatal or ("Ya estaba fuera" if r.skipped else "Completado"))

    console.print()
    console.print(table)


def main() -> None:
    console.print("[bold cyan]Desinstalación del plugin alquilado · Informes Avanzados[/bold cyan]\n")
    console.print(
        "[bold red]Esto borra el CÓDIGO y las TABLAS de block_advanced_reports en toda la "
        "plataforma[/bold red] (no es por curso). Úsalo solo tras confirmar que ya migraste "
        "todos los cursos que lo tenían activo.\n"
    )
    try:
        servers, _settings = load_inventory(INVENTORY_FILE)
        exec_mode = prompt_execution_mode()
        apply_execution_mode(servers, exec_mode)
        selected_servers = prompt_server_selection(servers)
        if not selected_servers:
            console.print("[yellow]No se seleccionaron plataformas. Operación cancelada.[/yellow]")
            return

        pending = pending_courses_by_server()
        blocked_names = set()
        for s in selected_servers:
            count = pending.get(s["name"], 0)
            if count:
                console.print(
                    f"[bold red]Aviso:[/bold red] «{s['name']}» todavía tiene {count} curso(s) con el "
                    "bloque alquilado activo según auditoria_resultado.json (¿corriste el PASO 3 ahí?)."
                )
                blocked_names.add(s["name"])

        if blocked_names:
            override = safe_text(
                "¿Continuar de todas formas con las plataformas marcadas en rojo? (y/n)",
                validate=lambda v: True if v and v.strip().lower() in {"y", "n"} else "Responde y o n.",
            )
            if override is None or override.strip().lower() != "y":
                selected_servers = [s for s in selected_servers if s["name"] not in blocked_names]
                console.print("[yellow]Se excluyen las plataformas con migración pendiente.[/yellow]")

        if not selected_servers:
            console.print("[yellow]Ninguna plataforma para procesar. Operación cancelada.[/yellow]")
            return

        console.print("\n[bold]Se desinstalará block_advanced_reports (con backup previo) en:[/bold]")
        for s in selected_servers:
            console.print(f"  • {s['name']} ({s['host']})")

        confirm = safe_text(
            "Escribe DESINSTALAR para confirmar (cualquier otra cosa cancela):",
        )
        if confirm is None or confirm.strip() != "DESINSTALAR":
            console.print("[yellow]Operación cancelada por el usuario.[/yellow]")
            return

        results = [uninstall_on_server(server) for server in selected_servers]

        for r in results:
            if r.skipped:
                console.print(f"[dim]— {r.server_name}: ya estaba fuera.[/dim]")
            elif r.success:
                console.print(f"[green]✅ {r.server_name}: plugin alquilado desinstalado.[/green]")
            else:
                console.print(f"[red]❌ {r.server_name}: {r.fatal}[/red]")

        print_summary(results)

    except KeyboardInterrupt:
        console.print("\n[yellow]Operación cancelada.[/yellow]")
    except Exception as exc:  # noqa: BLE001
        console.print(f"[bold red]Error fatal: {exc}[/bold red]")
        sys.exit(1)


if __name__ == "__main__":
    main()
