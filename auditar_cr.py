#!/usr/bin/env python3
"""
Auditoría de "Informes Avanzados" (alquilado, block_advanced_reports) vs.
"Configurable Reports" (propio, block_configurable_reports), por SSH.

Son dos plugins distintos (component y tablas diferentes). Detecta, en cada
plataforma del inventario, en qué cursos está añadido el bloque alquilado
(la señal de "informe activo" que hay que migrar) y en qué cursos ya está el
bloque propio con reportes (para confirmar cursos ya migrados). No modifica
nada: es de solo lectura.

Este es el PASO 1 del flujo de migración:

  1) auditar_cr.py                    → detecta cursos con el bloque alquilado activo (este script).
  2) desplegar_plugin_cr.py           → instala el plugin propio (código nuevo, plugin distinto).
  3) instalar_plantillas_cr.py        → agrega el bloque propio + sustituye reportes en los cursos detectados.
  4) desinstalar_plugin_alquilado_cr.py → (opcional, a nivel de plataforma) desinstala el alquilado.

Guarda un snapshot en auditoria_resultado.json con los courseids donde el
bloque alquilado está activo (needs_migration), para que el paso 3 no
requiera volver a teclearlos.
"""

from __future__ import annotations

import json
import posixpath
import sys
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Sequence

from rich.console import Console
from rich.table import Table

from cr_common import (
    CommandLog,
    RemoteCommandError,
    apply_execution_mode,
    connect_ssh,
    execute_remote,
    is_local_mode,
    load_inventory,
    logger,
    parse_php_output,
    prompt_execution_mode,
    prompt_server_selection,
    q,
    safe_cleanup,
)

SCRIPT_DIR = Path(__file__).resolve().parent
INVENTORY_FILE = SCRIPT_DIR / "inventario.json"
PHP_AUDITOR = SCRIPT_DIR / "audit_cr.php"
OUTPUT_FILE = SCRIPT_DIR / "auditoria_resultado.json"
REMOTE_TMP_DIR = "/tmp"

console = Console()


@dataclass
class AuditResult:
    server_name: str
    host: str
    moodle_path: str
    success: bool = False
    fatal: str = ""
    installed: Dict[str, Any] = field(default_factory=dict)
    courses: List[Dict[str, Any]] = field(default_factory=list)
    command_logs: List[CommandLog] = field(default_factory=list)


def audit_server(server: Dict[str, Any]) -> AuditResult:
    """Sube audit_cr.php, lo ejecuta como web_user y parsea el resultado."""
    result = AuditResult(
        server_name=server["name"],
        host=server["host"],
        moodle_path=str(server["moodle_path"]).rstrip("/"),
    )

    config_path = posixpath.join(result.moodle_path, "config.php")
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_php = posixpath.join(REMOTE_TMP_DIR, f"cr_audit_{token}.php")

    ssh = None
    sftp = None
    cleanup_errors: List[str] = []

    try:
        conn_label = "localmente" if is_local_mode(server) else "por SSH"
        console.print(f"[cyan]→ {result.server_name}: conectando {conn_label}...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        sftp.put(str(PHP_AUDITOR), remote_php)
        sftp.chmod(remote_php, 0o644)

        console.print(f"[cyan]→ {result.server_name}: ejecutando auditoría...[/cyan]")
        command = f"sudo -u {q(web_user)} env HOME=/tmp php {q(remote_php)} --config={q(config_path)}"
        run_log = execute_remote(
            result.command_logs, ssh, step="Auditoría", command=command,
            sudo_password=sudo_password, timeout=1800, fail_on_error=False,
        )

        payload = parse_php_output(run_log.stdout)
        if payload is None:
            result.fatal = (
                "No se pudo interpretar la salida del CLI PHP. "
                f"exit={run_log.exit_status}. "
                f"stderr={run_log.stderr.strip()[:500] or '[vacío]'} "
                f"stdout={run_log.stdout.strip()[:500] or '[vacío]'}"
            )
            return result

        if not payload.get("ok", False):
            result.fatal = str(payload.get("fatal", "Error desconocido del CLI PHP"))
            return result

        result.installed = payload.get("installed", {})
        result.courses = payload.get("courses", [])
        result.success = True
        logger.info("Auditoría OK en %s: %d curso(s) detectados", result.server_name, len(result.courses))

    except RemoteCommandError as exc:
        result.fatal = exc.log.stderr.rstrip("\n") or exc.log.stdout.rstrip("\n") or str(exc)
        logger.error("Auditoría FALLÓ en %s: %s", result.server_name, result.fatal)
    except Exception as exc:  # noqa: BLE001
        result.fatal = str(exc)
        logger.error("Auditoría EXCEPCIÓN en %s: %s", result.server_name, result.fatal)
    finally:
        if ssh is not None:
            safe_cleanup(ssh, f"rm -f {q(remote_php)}", cleanup_errors, sudo_password=sudo_password)
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

    return result


def print_results(results: Sequence[AuditResult]) -> None:
    for r in results:
        console.print()
        if not r.success:
            console.print(f"[bold red]✗ {r.server_name} ({r.host})[/bold red]: {r.fatal}")
            continue

        rented = (r.installed or {}).get("rented", {})
        own = (r.installed or {}).get("own", {})

        rented_v = rented.get("db_version") or "—"
        own_db_v = own.get("db_version") or "—"
        own_files_v = own.get("files_version") or "—"
        own_note = ""
        if own.get("files_version") and own.get("db_version") and str(own["files_version"]) != str(own["db_version"]):
            own_note = "  [yellow](ficheros y BD no coinciden: falta correr upgrade.php)[/yellow]"

        console.print(f"[bold]{r.server_name}[/bold] ({r.host})")
        console.print(
            f"  Alquilado (block_advanced_reports): "
            f"{'instalado' if rented.get('plugin_present') else '[dim]no en disco[/dim]'} | versión BD: {rented_v}"
        )
        console.print(
            f"  Propio (block_configurable_reports): "
            f"{'instalado' if own.get('plugin_present') else '[dim]no en disco[/dim]'} | "
            f"versión BD: {own_db_v} | versión ficheros: {own_files_v}{own_note}"
        )

        if not r.courses:
            console.print("  [dim]Sin cursos con ningún bloque añadido ni reportes propios existentes.[/dim]")
            continue

        table = Table()
        table.add_column("Curso", justify="right")
        table.add_column("Nombre")
        table.add_column("Alquilado\nañadido", justify="center")
        table.add_column("Propio\nañadido", justify="center")
        table.add_column("Reportes\npropios", justify="right")
        table.add_column("¿Migrar?", justify="center")

        for c in r.courses:
            nombre = c.get("fullname") or "[red](curso no encontrado)[/red]"
            rented_mark = "[red]sí[/red]" if c.get("rented_block_added") else "no"
            own_mark = "[green]sí[/green]" if c.get("own_block_added") else "no"
            migrar = "[bold yellow]SÍ[/bold yellow]" if c.get("needs_migration") else "—"
            table.add_row(
                str(c["courseid"]), nombre[:45], rented_mark, own_mark,
                str(c.get("own_report_count", 0)), migrar,
            )

        console.print(table)


def save_snapshot(results: Sequence[AuditResult]) -> None:
    """Guarda un JSON con los courseids a migrar por servidor (needs_migration)."""
    snapshot = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "servers": [],
    }
    for r in results:
        entry: Dict[str, Any] = {
            "name": r.server_name,
            "host": r.host,
            "moodle_path": r.moodle_path,
            "success": r.success,
            "fatal": r.fatal,
            "installed": r.installed,
            "affected_courseids": [
                c["courseid"] for c in r.courses if c.get("needs_migration") and not c.get("course_missing")
            ],
            "courses": r.courses,
        }
        snapshot["servers"].append(entry)

    OUTPUT_FILE.write_text(json.dumps(snapshot, indent=2, ensure_ascii=False), encoding="utf-8")
    console.print(f"\n[green]Snapshot guardado en:[/green] {OUTPUT_FILE}")


def main() -> None:
    console.print("[bold cyan]Auditoría · Informes Avanzados (alquilado) vs. Configurable Reports (propio)[/bold cyan]\n")
    try:
        if not PHP_AUDITOR.exists():
            raise FileNotFoundError(f"No se encontró el CLI PHP: {PHP_AUDITOR}")

        servers, _settings = load_inventory(INVENTORY_FILE)
        mode = prompt_execution_mode()
        apply_execution_mode(servers, mode)
        selected_servers = prompt_server_selection(servers)
        if not selected_servers:
            console.print("[yellow]No se seleccionaron plataformas. Operación cancelada.[/yellow]")
            return

        results = [audit_server(server) for server in selected_servers]

        print_results(results)
        save_snapshot(results)

    except KeyboardInterrupt:
        console.print("\n[yellow]Operación cancelada.[/yellow]")
    except Exception as exc:  # noqa: BLE001
        console.print(f"[bold red]Error fatal: {exc}[/bold red]")
        sys.exit(1)


if __name__ == "__main__":
    main()
