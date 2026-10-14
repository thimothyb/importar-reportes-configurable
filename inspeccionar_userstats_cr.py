#!/usr/bin/env python3
"""
Inspeccionar la estructura interna de columnas userstats (iTOP)
y userstatsadvanced (nuestro plugin) para documentar los campos
que almacenan items seleccionados y construir la migración.

Sube inspeccionar_userstats_cr.php al servidor, lo ejecuta y guarda
el resultado completo en inspeccionar_userstats_resultado.json.

Uso:
  python inspeccionar_userstats_cr.py

@package   block_configurable_reports
@copyright 2026 Awakelab
"""

from __future__ import annotations

import json
import os
import sys
import uuid
from pathlib import Path

from rich.console import Console
from rich.table import Table
from rich import print_json

sys.path.insert(0, str(Path(__file__).resolve().parent))

from cr_common import (
    CommandLog,
    connect_ssh,
    console,
    execute_remote,
    load_inventory,
    logger,
    parse_php_output,
    prompt_execution_mode,
    prompt_server_selection,
    apply_execution_mode,
    q,
    run_remote_command,
    safe_text,
)

REMOTE_TMP_DIR = "/tmp"
PHP_SCRIPT = "inspeccionar_userstats_cr.php"
SCRIPT_DIR = Path(__file__).resolve().parent


def upload_php_script(ssh, sftp, server: dict) -> str:
    local_path = SCRIPT_DIR / PHP_SCRIPT
    if not local_path.exists():
        raise FileNotFoundError(f"No se encontró {PHP_SCRIPT} en {SCRIPT_DIR}")
    token = uuid.uuid4().hex[:8]
    remote_path = f"{REMOTE_TMP_DIR}/cr_inspect_userstats_{token}.php"
    sftp.put(str(local_path), remote_path)
    sftp.chmod(remote_path, 0o644)
    return remote_path


def run_inspection(
    ssh, server: dict, remote_php: str, courses: str = "all",
    sudo_password: str | None = None,
) -> dict:
    moodle_path = server["moodle_path"]
    config_path = f"{moodle_path}/config.php"
    web_user = server["web_user"]

    cmd = (
        f"sudo -u {q(web_user)} php {q(remote_php)}"
        f" --config={q(config_path)}"
        f" --courses={q(courses)}"
    )

    command_logs: list[CommandLog] = []
    log = execute_remote(
        command_logs, ssh,
        step="Inspeccionar userstats",
        command=cmd,
        sudo_password=sudo_password,
        timeout=300,
        fail_on_error=False,
    )

    result = parse_php_output(log.stdout)
    if result is None:
        result = parse_php_output(log.stderr)
    if result is None:
        raise RuntimeError(
            f"Script PHP no emitió JSON válido. Exit: {log.exit_status}\n"
            f"STDERR: {log.stderr[:500]}"
        )
    if not result.get("ok"):
        raise RuntimeError(f"Error PHP: {result.get('fatal', result)}")
    return result


def show_summary(result: dict) -> None:
    courses = result.get("courses", [])
    console.print(f"\n[bold cyan]═══ INSPECCIÓN USERSTATS ═══[/bold cyan]")
    console.print(f"  Tabla iTOP: [yellow]{result.get('rented_table', '?')}[/yellow]")
    console.print(f"  Cursos con columnas userstats: {result.get('total_courses', 0)}")

    for course in courses:
        console.print(
            f"\n  [bold]Curso {course['courseid']} — {course.get('fullname', '')}[/bold]"
        )
        for rep in course.get("reports", []):
            own = rep.get("own_report", {})
            itop = rep.get("itop_report", {})
            own_cols = rep.get("own_userstatsadvanced_columns", [])
            itop_cols = rep.get("itop_userstats_columns", [])

            console.print(
                f"\n    Reporte: {own.get('name', '?')} (id={own.get('id')}) ↔ "
                f"{itop.get('name', '?')} (id={itop.get('id')})"
            )
            console.print(
                f"    Columnas userstatsadvanced (nuestro): {len(own_cols)}"
            )
            console.print(
                f"    Columnas userstats (iTOP): {len(itop_cols)}"
            )

            # Mostrar campos de cada columna de nuestro plugin.
            if own_cols:
                console.print("    [cyan]── Nuestras columnas (userstatsadvanced) ──[/cyan]")
                for col in own_cols:
                    fd = col.get("formdata", {})
                    console.print(f"      [{col.get('element_index')}] {col.get('summary', '?')}")
                    # Mostrar todos los campos del formdata.
                    for k, v in sorted(fd.items()):
                        if k.startswith('_'): continue
                        val_str = json.dumps(v, ensure_ascii=False) if isinstance(v, (dict, list)) else str(v)
                        if len(val_str) > 200:
                            val_str = val_str[:200] + "..."
                        console.print(f"        {k}: {val_str}")

            # Mostrar campos de cada columna iTOP.
            if itop_cols:
                console.print("    [yellow]── Columnas iTOP (userstats) ──[/yellow]")
                for col in itop_cols:
                    fd = col.get("formdata", {})
                    console.print(f"      [{col.get('element_index')}] {col.get('summary', '?')}")
                    for k, v in sorted(fd.items()):
                        if k.startswith('_'): continue
                        val_str = json.dumps(v, ensure_ascii=False) if isinstance(v, (dict, list)) else str(v)
                        if len(val_str) > 200:
                            val_str = val_str[:200] + "..."
                        console.print(f"        {k}: {val_str}")


def main():
    console.print("[bold cyan]╔══════════════════════════════════════════════════════════╗[/bold cyan]")
    console.print("[bold cyan]║  Inspección: Columnas userstats / userstatsadvanced     ║[/bold cyan]")
    console.print("[bold cyan]╚══════════════════════════════════════════════════════════╝[/bold cyan]")

    inv_path = SCRIPT_DIR / "inventario.json"
    if not inv_path.exists():
        console.print(f"[red]No se encontró {inv_path}[/red]")
        sys.exit(1)

    servers, settings = load_inventory(inv_path)
    mode = prompt_execution_mode()
    apply_execution_mode(servers, mode)
    selected = prompt_server_selection(servers)

    if not selected:
        console.print("[yellow]No se seleccionaron servidores.[/yellow]")
        sys.exit(0)

    courses_input = safe_text(
        "IDs de cursos (separados por coma, o 'all')",
        default="601"
    )

    for server in selected:
        console.print(f"\n[bold blue]{'═' * 60}[/bold blue]")
        console.print(f"[bold blue]  Servidor: {server['name']}[/bold blue]")
        console.print(f"[bold blue]{'═' * 60}[/bold blue]")

        ssh = None
        remote_php = None
        try:
            ssh = connect_ssh(server)
            sftp = ssh.open_sftp()

            console.print("  Subiendo script PHP...")
            remote_php = upload_php_script(ssh, sftp, server)

            sudo_pw = server.get("sudo_password") or (
                settings.get("sudo_password") if server.get("sudo_requires_password") else None
            )
            console.print("  Ejecutando inspección...")
            result = run_inspection(
                ssh, server, remote_php,
                courses=courses_input,
                sudo_password=sudo_pw,
            )

            show_summary(result)

            # Guardar resultado completo.
            out_file = SCRIPT_DIR / f"inspeccionar_userstats_resultado_{server['name']}.json"
            out_file.write_text(
                json.dumps(result, indent=2, ensure_ascii=False),
                encoding="utf-8",
            )
            console.print(f"\n  [green]Resultado guardado en: {out_file}[/green]")

        except Exception as e:
            console.print(f"  [red]Error: {e}[/red]")
            logger.exception("Error en servidor %s", server.get("name", "?"))
        finally:
            if ssh and remote_php:
                try:
                    run_remote_command(ssh, f"rm -f {q(remote_php)}")
                except Exception:
                    pass
            if ssh:
                try:
                    ssh.close()
                except Exception:
                    pass

    console.print("\n[bold green]Inspección completa.[/bold green]")


if __name__ == "__main__":
    main()
