#!/usr/bin/env python3
"""
CLI interactiva para automatizar la INSTALACIÓN/SUSTITUCIÓN DE PLANTILLAS del
bloque "Configurable Reports" (reportes configurables) en cursos concretos de
una o varias plataformas Moodle, por SSH.

Patrón idéntico al instalador de plugins por SSH:
  - inventario.json con los servidores
  - paramiko (SSH + SFTP) / questionary / rich
  - sube los .xml + un CLI PHP a /tmp y los ejecuta como el usuario web
    (sudo -u www-data), reutilizando la lógica nativa del plugin para importar.

El plugin es a nivel de plataforma (ya debe estar instalado); las plantillas
son a nivel de CURSO: se importan en cada courseid indicado.

Dos modos de operación:

  1) Instalación normal — cursos indicados a mano (o en inventario.json).
     Idempotente: re-ejecutar no duplica (omite los que ya existan; con
     --force / opción "actualizar" sobrescribe los del mismo nombre).

  2) Migración (PASO 3 del flujo) — lee auditoria_resultado.json (generado
     por auditar_cr.py) y, para los cursos allí detectados (donde el bloque
     alquilado "Informes Avanzados" está activo), agrega automáticamente el
     bloque propio a la página del curso si falta (--addblock), borra los
     reportes PROPIOS que pudieran existir de una corrida previa (--wipe) y
     crea las 8 plantillas propias. NO toca el plugin alquilado (código ni
     datos): eso lo hace, si se quiere, desinstalar_plugin_alquilado_cr.py a
     nivel de plataforma. Requiere confirmación explícita por ser masivo.
"""

from __future__ import annotations

import json
import posixpath
import sys
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence

import questionary
from rich.console import Console
from rich.table import Table

from cr_common import (
    CommandLog,
    RemoteCommandError,
    connect_ssh,
    execute_remote,
    load_inventory,
    logger,
    normalize_courseids,
    parse_php_output,
    prompt_server_selection,
    q,
    safe_cleanup,
)

# --- Rutas y constantes -----------------------------------------------------
SCRIPT_DIR = Path(__file__).resolve().parent
INVENTORY_FILE = SCRIPT_DIR / "inventario.json"
PHP_IMPORTER = SCRIPT_DIR / "import_cr_templates.php"
PHP_RESTORER = SCRIPT_DIR / "restore_cr_templates.php"
DEFAULT_TEMPLATES_DIR = SCRIPT_DIR / "plantillas"
AUDIT_SNAPSHOT_FILE = SCRIPT_DIR / "auditoria_resultado.json"
SNAPSHOTS_DIR = SCRIPT_DIR / "snapshots"
REMOTE_TMP_DIR = "/tmp"

# Estados por reporte (los que emite el CLI PHP).
STATUS_CREATED = "created"
STATUS_UPDATED = "updated"
STATUS_SKIPPED = "skipped"
STATUS_WIPED = "wiped"
STATUS_BLOCK_ADDED = "block_added"
STATUS_BLOCK_PRESENT = "block_present"
STATUS_ERROR = "error"

STATUS_DISPLAY: Dict[str, str] = {
    STATUS_CREATED: "[green]Creado[/green]",
    STATUS_UPDATED: "[cyan]Actualizado[/cyan]",
    STATUS_SKIPPED: "[yellow]Ya existía[/yellow]",
    STATUS_WIPED: "[magenta]Vaciado curso[/magenta]",
    STATUS_BLOCK_ADDED: "[blue]Bloque agregado[/blue]",
    STATUS_BLOCK_PRESENT: "[dim]Bloque ya estaba[/dim]",
    STATUS_ERROR: "[red]Error[/red]",
}

console = Console()


# --- Modelos ----------------------------------------------------------------
@dataclass
class ReportResult:
    """Resultado de importar una plantilla en un curso."""

    course: Any
    coursename: Optional[str]
    file: Optional[str]
    report: Optional[str]
    status: str
    message: str


@dataclass
class ServerResult:
    """Resultado final por servidor."""

    server_name: str
    success: bool = False
    fatal: str = ""
    report_results: List[ReportResult] = field(default_factory=list)
    command_logs: List[CommandLog] = field(default_factory=list)


# --- Snapshots (para rollback) ----------------------------------------------
def _save_snapshot(server_name: str, snapshots: List[Dict[str, Any]]) -> Optional[Path]:
    """Guarda el snapshot de reportes previos en snapshots/<servidor>/<timestamp>.json."""
    if not snapshots:
        return None
    from datetime import datetime as _dt, timezone as _tz
    timestamp = _dt.now().strftime("%Y%m%d_%H%M%S")
    target_dir = SNAPSHOTS_DIR / server_name
    target_dir.mkdir(parents=True, exist_ok=True)
    snapshot_file = target_dir / f"reportes_{timestamp}.json"
    data = {
        "server": server_name,
        "snapshot_date": _dt.now(_tz.utc).isoformat(),
        "courses": snapshots,
    }
    snapshot_file.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
    console.print(f"[green]   Snapshot guardado:[/green] {snapshot_file}")
    logger.info("Snapshot guardado: %s (%d curso(s))", snapshot_file, len(snapshots))
    return snapshot_file


def _list_snapshots(server_name: str) -> List[Path]:
    """Lista archivos de snapshot disponibles para un servidor, del más reciente al más antiguo."""
    target_dir = SNAPSHOTS_DIR / server_name
    if not target_dir.is_dir():
        return []
    return sorted(target_dir.glob("reportes_*.json"), reverse=True)


def rollback_on_server(
    server: Dict[str, Any],
    snapshot_file: Path,
) -> ServerResult:
    """
    Restaura reportes desde un snapshot JSON:
    1) Sube el snapshot + restore_cr_templates.php a /tmp.
    2) Ejecuta el CLI PHP como usuario web.
    3) Limpia temporales.
    """
    result = ServerResult(server_name=server["name"])

    moodle_path = str(server["moodle_path"]).rstrip("/")
    config_path = posixpath.join(moodle_path, "config.php")
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_php = posixpath.join(REMOTE_TMP_DIR, f"cr_restore_{token}.php")
    remote_snapshot = posixpath.join(REMOTE_TMP_DIR, f"cr_snapshot_{token}.json")

    ssh = None
    sftp = None
    cleanup_errors: List[str] = []

    try:
        console.print(f"[cyan]→ {result.server_name}: conectando por SSH...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        console.print(f"[cyan]→ {result.server_name}: subiendo snapshot + CLI de restauración...[/cyan]")
        sftp.put(str(PHP_RESTORER), remote_php)
        sftp.chmod(remote_php, 0o644)
        sftp.put(str(snapshot_file), remote_snapshot)
        sftp.chmod(remote_snapshot, 0o644)

        console.print(f"[cyan]→ {result.server_name}: ejecutando restauración...[/cyan]")
        php_command = (
            f"sudo -u {q(web_user)} env HOME=/tmp php {q(remote_php)} "
            f"--config={q(config_path)} "
            f"--snapshot={q(remote_snapshot)}"
        )
        run_log = execute_remote(result.command_logs, ssh, step="Rollback - restaurar",
                                  command=php_command, sudo_password=sudo_password,
                                  timeout=3600, fail_on_error=False)

        payload = parse_php_output(run_log.stdout)
        if payload is None:
            result.fatal = (
                "No se pudo interpretar la salida del CLI PHP. "
                f"exit={run_log.exit_status}. "
                f"stderr={run_log.stderr.strip()[:500] or '[vacío]'} "
                f"stdout={run_log.stdout.strip()[:500] or '[vacío]'}"
            )
            return result

        if not payload.get("ok", False) and payload.get("fatal"):
            result.fatal = str(payload["fatal"])

        for item in payload.get("results", []):
            result.report_results.append(ReportResult(
                course=item.get("courseid"),
                coursename=item.get("coursename"),
                file=None,
                report=None,
                status=item.get("status", STATUS_ERROR),
                message=item.get("message", ""),
            ))

    except RemoteCommandError as exc:
        result.fatal = exc.log.stderr.rstrip("\n") or exc.log.stdout.rstrip("\n") or str(exc)
    except Exception as exc:  # noqa: BLE001
        result.fatal = str(exc)
    finally:
        if ssh is not None:
            safe_cleanup(ssh, f"rm -f {q(remote_php)} {q(remote_snapshot)}", cleanup_errors,
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
            result.fatal = "Limpieza con incidencias: " + " | ".join(cleanup_errors)

    has_error = any(r.status == STATUS_ERROR for r in result.report_results)
    result.success = (not result.fatal) and (not has_error) and bool(result.report_results)
    if result.success:
        logger.info("Rollback OK en %s", result.server_name)
    else:
        logger.error("Rollback con problemas en %s: %s", result.server_name, result.fatal or "errores en resultados")
    return result


# --- Flujo por servidor -----------------------------------------------------
def install_templates_on_server(
    server: Dict[str, Any],
    templates_dir: Path,
    courseids: List[str],
    *,
    force: bool,
    wipe: bool,
    addblock: bool,
    owner: Optional[int],
) -> ServerResult:
    """
    1) Conectar SSH/SFTP.
    2) Crear carpeta temporal remota y subir los .xml + el CLI PHP.
    3) Ejecutar el CLI PHP como usuario web (importa por curso).
    4) finally: borrar la carpeta temporal y el script.
    """
    result = ServerResult(server_name=server["name"])

    moodle_path = str(server["moodle_path"]).rstrip("/")
    config_path = posixpath.join(moodle_path, "config.php")
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_dir = posixpath.join(REMOTE_TMP_DIR, f"cr_tpl_{token}")
    remote_php = posixpath.join(REMOTE_TMP_DIR, f"cr_import_{token}.php")

    ssh = None
    sftp = None
    cleanup_errors: List[str] = []

    try:
        console.print(f"[cyan]→ {result.server_name}: conectando por SSH...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        console.print(f"[cyan]→ {result.server_name}: Paso 1/3 (subir plantillas + CLI)[/cyan]")
        execute_remote(result.command_logs, ssh, step="Paso 1 - Crear carpeta temporal",
                        command=f"mkdir -p {q(remote_dir)} && chmod 755 {q(remote_dir)}",
                        sudo_password=sudo_password)

        xml_files = sorted(templates_dir.glob("*.xml"))
        if not xml_files:
            raise RuntimeError(f"No hay archivos .xml en {templates_dir}")
        for xml_file in xml_files:
            sftp.put(str(xml_file), posixpath.join(remote_dir, xml_file.name))
            sftp.chmod(posixpath.join(remote_dir, xml_file.name), 0o644)

        sftp.put(str(PHP_IMPORTER), remote_php)
        sftp.chmod(remote_php, 0o644)

        console.print(f"[cyan]→ {result.server_name}: Paso 2/3 (procesando {len(courseids)} curso(s))[/cyan]")
        php_command_parts = [
            f"sudo -u {q(web_user)} env HOME=/tmp php {q(remote_php)}",
            f"--config={q(config_path)}",
            f"--dir={q(remote_dir)}",
            f"--courses={q(','.join(courseids))}",
        ]
        if owner is not None:
            php_command_parts.append(f"--owner={q(str(owner))}")
        if force:
            php_command_parts.append("--force")
        if wipe:
            php_command_parts.append("--wipe")
        if addblock:
            php_command_parts.append("--addblock")
        php_command = " ".join(php_command_parts)

        run_log = execute_remote(result.command_logs, ssh, step="Paso 2 - Importar plantillas",
                                  command=php_command, sudo_password=sudo_password,
                                  timeout=3600, fail_on_error=False)

        payload = parse_php_output(run_log.stdout)
        if payload is None:
            result.fatal = (
                "No se pudo interpretar la salida del CLI PHP. "
                f"exit={run_log.exit_status}. "
                f"stderr={run_log.stderr.strip()[:500] or '[vacío]'} "
                f"stdout={run_log.stdout.strip()[:500] or '[vacío]'}"
            )
            return result

        if not payload.get("ok", False) and payload.get("fatal"):
            result.fatal = str(payload["fatal"])

        # Guardar snapshot de reportes previos (para rollback posterior).
        snapshots = payload.get("snapshots", [])
        if snapshots:
            _save_snapshot(server["name"], snapshots)

        for item in payload.get("results", []):
            result.report_results.append(ReportResult(
                course=item.get("course"),
                coursename=item.get("coursename"),
                file=item.get("file"),
                report=item.get("report"),
                status=item.get("status", STATUS_ERROR),
                message=item.get("message", ""),
            ))

    except RemoteCommandError as exc:
        result.fatal = exc.log.stderr.rstrip("\n") or exc.log.stdout.rstrip("\n") or str(exc)
    except Exception as exc:  # noqa: BLE001
        result.fatal = str(exc)
    finally:
        if ssh is not None:
            console.print(f"[cyan]→ {result.server_name}: Paso 3/3 (limpiar temporales)[/cyan]")
            safe_cleanup(ssh, f"rm -rf {q(remote_dir)} {q(remote_php)}", cleanup_errors,
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
            result.fatal = "Limpieza con incidencias: " + " | ".join(cleanup_errors)

    has_error = any(r.status == STATUS_ERROR for r in result.report_results)
    result.success = (not result.fatal) and (not has_error) and bool(result.report_results)
    if result.success:
        logger.info("Plantillas OK en %s: %d resultado(s)", result.server_name, len(result.report_results))
    else:
        logger.error("Plantillas con problemas en %s: fatal=%s errores=%d",
                      result.server_name, result.fatal or "ninguno",
                      sum(1 for r in result.report_results if r.status == STATUS_ERROR))
    return result


# --- Interacción ------------------------------------------------------------
def resolve_courseids_for_server(server: Dict[str, Any], settings: Dict[str, Any]) -> List[str]:
    """
    Obtiene los cursos destino para un servidor (modo instalación normal):
      1) server["report_courseids"] (lista en inventario), si existe.
      2) settings["report_courseids"] global, si existe.
      3) prompt interactivo (coma-separado).
    """
    ids = normalize_courseids(server.get("report_courseids"))
    if ids:
        return ids
    ids = normalize_courseids(settings.get("report_courseids"))
    if ids:
        return ids

    answer = questionary.text(
        f"IDs (o shortnames) de cursos para «{server['name']}», separados por coma:",
        validate=lambda v: True if normalize_courseids(v) else "Indica al menos un curso.",
    ).ask()
    if answer is None:
        raise KeyboardInterrupt
    return normalize_courseids(answer)


def load_migration_targets(servers: Sequence[Dict[str, Any]]) -> Dict[str, List[str]]:
    """
    Modo migración: lee auditoria_resultado.json y cruza por nombre de servidor
    con el inventario. Devuelve {server_name: [courseids]} solo para los que
    tengan cursos afectados.
    """
    if not AUDIT_SNAPSHOT_FILE.exists():
        raise FileNotFoundError(
            f"No se encontró {AUDIT_SNAPSHOT_FILE.name}. Ejecuta primero auditar_cr.py."
        )
    snapshot = json.loads(AUDIT_SNAPSHOT_FILE.read_text(encoding="utf-8"))
    by_name = {entry["name"]: entry for entry in snapshot.get("servers", [])}

    server_names = {s["name"] for s in servers}
    targets: Dict[str, List[str]] = {}
    for name, entry in by_name.items():
        if name not in server_names:
            console.print(f"[yellow]Aviso: «{name}» está en la auditoría pero no en inventario.json; se omite.[/yellow]")
            continue
        if not entry.get("success"):
            console.print(f"[yellow]Aviso: la auditoría de «{name}» falló ({entry.get('fatal')}); se omite.[/yellow]")
            continue
        courseids = [str(cid) for cid in entry.get("affected_courseids", [])]
        if courseids:
            targets[name] = courseids

    return targets


def prompt_mode() -> str:
    answer = questionary.select(
        "¿Qué quieres hacer?",
        choices=[
            questionary.Choice("Instalación normal (cursos indicados a mano)", "normal"),
            questionary.Choice(
                "Migración: sustituir TODOS los reportes en los cursos detectados por auditar_cr.py",
                "migration",
            ),
            questionary.Choice(
                "Rollback: restaurar reportes desde un snapshot previo",
                "rollback",
            ),
        ],
    ).ask()
    if answer is None:
        raise KeyboardInterrupt
    return answer


def confirm_execution(server_count: int, template_count: int, *, force: bool, wipe: bool) -> bool:
    """Confirmación explícita (y/n)."""
    if wipe:
        mode = "BORRANDO todos los reportes existentes y sustituyéndolos"
    elif force:
        mode = "ACTUALIZANDO existentes"
    else:
        mode = "omitiendo existentes"
    answer = questionary.text(
        f"¿Importar {template_count} plantilla(s) en {server_count} plataforma(s) [{mode}]? (y/n)",
        validate=lambda v: True if v and v.strip().lower() in {"y", "n"} else "Responde y o n.",
    ).ask()
    if answer is None:
        raise KeyboardInterrupt
    return answer.strip().lower() == "y"


# --- Resumen ----------------------------------------------------------------
def print_summary(results: Sequence[ServerResult]) -> None:
    """Tabla final detallada por servidor/curso/reporte."""
    for server_result in results:
        table = Table(title=f"Servidor: {server_result.server_name}")
        table.add_column("Curso", justify="right")
        table.add_column("Nombre curso")
        table.add_column("Plantilla")
        table.add_column("Estado", justify="center")
        table.add_column("Detalle")

        if server_result.fatal and not server_result.report_results:
            table.add_row("—", "—", "—", "[red]Fallo[/red]", server_result.fatal)
        else:
            for r in server_result.report_results:
                status = STATUS_DISPLAY.get(r.status, "[red]Error[/red]")
                table.add_row(
                    str(r.course),
                    (r.coursename or "—")[:40],
                    (r.report or r.file or "—"),
                    status,
                    r.message or "-",
                )
            if server_result.fatal:
                table.add_row("—", "—", "—", "[red]Aviso[/red]", server_result.fatal)

        console.print()
        console.print(table)

    totals = {
        STATUS_CREATED: 0, STATUS_UPDATED: 0, STATUS_SKIPPED: 0, STATUS_WIPED: 0,
        STATUS_BLOCK_ADDED: 0, STATUS_BLOCK_PRESENT: 0, STATUS_ERROR: 0,
    }
    for sr in results:
        for r in sr.report_results:
            totals[r.status] = totals.get(r.status, 0) + 1
    console.print()
    console.print(
        f"[bold]Totales[/bold] — "
        f"[green]Creados: {totals[STATUS_CREATED]}[/green] | "
        f"[cyan]Actualizados: {totals[STATUS_UPDATED]}[/cyan] | "
        f"[yellow]Ya existían: {totals[STATUS_SKIPPED]}[/yellow] | "
        f"[magenta]Cursos vaciados: {totals[STATUS_WIPED]}[/magenta] | "
        f"[blue]Bloques agregados: {totals[STATUS_BLOCK_ADDED]}[/blue] | "
        f"[red]Errores: {totals[STATUS_ERROR]}[/red]"
    )


# --- Rollback interactivo ---------------------------------------------------
def _run_rollback(servers: Sequence[Dict[str, Any]]) -> None:
    """Flujo interactivo de rollback: elegir servidor, snapshot y restaurar."""
    if not PHP_RESTORER.exists():
        raise FileNotFoundError(f"No se encontró el CLI PHP de restauración: {PHP_RESTORER}")

    selected_servers = prompt_server_selection(servers)
    if not selected_servers:
        console.print("[yellow]No se seleccionaron plataformas. Operación cancelada.[/yellow]")
        return

    all_results: List[ServerResult] = []

    for server in selected_servers:
        snapshots = _list_snapshots(server["name"])
        if not snapshots:
            console.print(f"[yellow]No hay snapshots para «{server['name']}». Se omite.[/yellow]")
            continue

        # Mostrar snapshots disponibles y dejar elegir.
        choices = []
        for snap_path in snapshots:
            snap_data = json.loads(snap_path.read_text(encoding="utf-8"))
            date_str = snap_data.get("snapshot_date", "fecha desconocida")
            courses = snap_data.get("courses", [])
            total_reports = sum(c.get("report_count", 0) for c in courses)
            label = f"{snap_path.name} — {date_str[:19]} — {len(courses)} curso(s), {total_reports} reporte(s)"
            choices.append(questionary.Choice(label, str(snap_path)))

        chosen = questionary.select(
            f"Snapshot a restaurar en «{server['name']}»:",
            choices=choices,
        ).ask()
        if chosen is None:
            raise KeyboardInterrupt

        snapshot_file = Path(chosen)

        # Mostrar detalle del snapshot.
        snap_data = json.loads(snapshot_file.read_text(encoding="utf-8"))
        console.print(f"\n[bold]Snapshot:[/bold] {snapshot_file.name}")
        console.print(f"[bold]Fecha:[/bold] {snap_data.get('snapshot_date', '?')}")
        for c in snap_data.get("courses", []):
            console.print(f"  • Curso {c['courseid']} ({c.get('coursename', '?')}): {c.get('report_count', 0)} reporte(s)")

        confirm = questionary.text(
            f"¿Restaurar estos reportes en «{server['name']}»? "
            "Esto BORRARÁ los reportes actuales y los reemplazará por los del snapshot. (y/n)",
            validate=lambda v: True if v and v.strip().lower() in {"y", "n"} else "Responde y o n.",
        ).ask()
        if confirm is None or confirm.strip().lower() != "y":
            console.print(f"[yellow]Rollback cancelado para «{server['name']}».[/yellow]")
            continue

        result = rollback_on_server(server, snapshot_file)
        all_results.append(result)

        if result.success:
            console.print(f"[green]✅ {result.server_name}: reportes restaurados.[/green]")
        else:
            console.print(f"[red]❌ {result.server_name}: {result.fatal}[/red]")

    if all_results:
        for sr in all_results:
            table = Table(title=f"Rollback: {sr.server_name}")
            table.add_column("Curso", justify="right")
            table.add_column("Nombre curso")
            table.add_column("Estado", justify="center")
            table.add_column("Detalle")

            if sr.fatal and not sr.report_results:
                table.add_row("—", "—", "[red]Fallo[/red]", sr.fatal)
            else:
                for r in sr.report_results:
                    status_display = "[green]Restaurado[/green]" if r.status == "restored" else "[red]Error[/red]"
                    table.add_row(str(r.course), (r.coursename or "—")[:40], status_display, r.message or "—")
                if sr.fatal:
                    table.add_row("—", "—", "[red]Aviso[/red]", sr.fatal)

            console.print()
            console.print(table)


# --- main -------------------------------------------------------------------
def main() -> None:
    console.print("[bold cyan]Instalador/Migrador de plantillas · Configurable Reports (por SSH)[/bold cyan]\n")

    try:
        servers, settings = load_inventory(INVENTORY_FILE)

        mode = prompt_mode()

        if mode == "rollback":
            _run_rollback(servers)
            return

        if not PHP_IMPORTER.exists():
            raise FileNotFoundError(f"No se encontró el CLI PHP: {PHP_IMPORTER}")

        default_dir = str(settings.get("templates_dir") or DEFAULT_TEMPLATES_DIR)
        dir_answer = questionary.text("Carpeta local con las plantillas (.xml):", default=default_dir).ask()
        if dir_answer is None:
            raise KeyboardInterrupt
        templates_dir = Path(dir_answer.strip().strip('"').strip("'")).expanduser()
        if not templates_dir.is_dir():
            raise NotADirectoryError(f"No es una carpeta válida: {templates_dir}")
        xml_files = sorted(templates_dir.glob("*.xml"))
        if not xml_files:
            raise FileNotFoundError(f"No hay archivos .xml en {templates_dir}")
        console.print(f"[green]Plantillas detectadas:[/green] {len(xml_files)}")
        for f in xml_files:
            console.print(f"   • {f.name}")

        owner = settings.get("owner")
        owner = int(owner) if isinstance(owner, (int, str)) and str(owner).isdigit() else None

        wipe = mode == "migration"
        addblock = mode == "migration"

        if mode == "normal":
            wipe = questionary.confirm(
                "¿Borrar TODOS los reportes propios existentes en esos cursos antes de crear los nuevos?",
                default=False,
            ).ask()
            if wipe is None:
                raise KeyboardInterrupt

        force = wipe or bool(settings.get("force", False))

        if mode == "normal" and not wipe and not settings.get("force"):
            force = questionary.confirm(
                "¿Actualizar reportes existentes con el mismo nombre? (No = omitirlos)",
                default=False,
            ).ask()
            if force is None:
                raise KeyboardInterrupt

        courseids_by_server: Dict[str, List[str]] = {}

        if mode == "migration":
            targets = load_migration_targets(servers)
            if not targets:
                console.print("[yellow]La auditoría no dejó cursos afectados. Nada que migrar.[/yellow]")
                return
            console.print("\n[bold]Cursos detectados por auditar_cr.py:[/bold]")
            for name, ids in targets.items():
                console.print(f"  • {name}: {len(ids)} curso(s) → {', '.join(ids)}")
            console.print(
                "\n[bold red]Advertencia:[/bold red] en esos cursos se agregará el bloque propio si "
                "falta, se borrará cualquier reporte PROPIO de una corrida previa, y se crearán las 8 "
                "plantillas. No se toca el código ni los datos del plugin alquilado."
            )
            selected_servers = [s for s in servers if s["name"] in targets]
            courseids_by_server = targets
        else:
            selected_servers = prompt_server_selection(servers)
            if not selected_servers:
                console.print("[yellow]No se seleccionaron plataformas. Operación cancelada.[/yellow]")
                return
            for server in selected_servers:
                ids = resolve_courseids_for_server(server, settings)
                if not ids:
                    console.print(f"[yellow]Sin cursos para {server['name']}: se omite.[/yellow]")
                courseids_by_server[server["name"]] = ids
            selected_servers = [s for s in selected_servers if courseids_by_server.get(s["name"])]

        if not selected_servers:
            console.print("[yellow]Ningún servidor con cursos definidos. Operación cancelada.[/yellow]")
            return

        if not confirm_execution(len(selected_servers), len(xml_files), force=force, wipe=wipe):
            console.print("[yellow]Operación cancelada por el usuario.[/yellow]")
            return

        all_results: List[ServerResult] = []
        for server in selected_servers:
            result = install_templates_on_server(
                server=server,
                templates_dir=templates_dir,
                courseids=courseids_by_server[server["name"]],
                force=force,
                wipe=wipe,
                addblock=addblock,
                owner=owner,
            )
            all_results.append(result)

            if result.success:
                console.print(f"[green]✅ {result.server_name}: completado.[/green]")
            elif result.fatal and not result.report_results:
                console.print(f"[red]❌ {result.server_name}: {result.fatal}[/red]")
            else:
                console.print(f"[yellow]⚠ {result.server_name}: completado con avisos/errores (ver resumen).[/yellow]")

        print_summary(all_results)

    except KeyboardInterrupt:
        console.print("\n[yellow]Operación cancelada.[/yellow]")
    except Exception as exc:  # noqa: BLE001
        console.print(f"[bold red]Error fatal: {exc}[/bold red]")
        sys.exit(1)


if __name__ == "__main__":
    main()
