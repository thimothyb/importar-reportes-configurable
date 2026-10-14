#!/usr/bin/env python3
"""
Migrar formato de exportación de reportes iTOP → Configurable Reports.

Lee el campo 'export' de cada reporte iTOP y actualiza el campo equivalente
en nuestro plugin, reemplazando formatos no soportados (ej: slk → pdf).

Flujo:
  1. Sube migrar_exportformat_itop_cr.php al servidor.
  2. Lo ejecuta con --courses=all (o ids específicos) en modo dry-run.
  3. Muestra el resumen de cambios propuestos.
  4. Si el usuario confirma, lo vuelve a ejecutar con --apply.

Uso:
  python3 migrar_exportformat_itop_cr.py

@package   block_configurable_reports
@copyright 2026 Awakelab
"""

from __future__ import annotations

import json
import sys
import uuid
from pathlib import Path

from rich.console import Console
from rich.table import Table

sys.path.insert(0, str(Path(__file__).resolve().parent))

from cr_common import (
    CommandLog,
    apply_execution_mode,
    connect_ssh,
    console,
    execute_remote,
    load_inventory,
    logger,
    parse_php_output,
    prompt_execution_mode,
    prompt_server_selection,
    q,
    run_remote_command,
    safe_confirm,
    safe_text,
)

REMOTE_TMP_DIR = "/tmp"
PHP_SCRIPT = "migrar_exportformat_itop_cr.php"
SCRIPT_DIR = Path(__file__).resolve().parent


def upload_php_script(ssh, sftp, server: dict) -> str:
    local_path = SCRIPT_DIR / PHP_SCRIPT
    if not local_path.exists():
        raise FileNotFoundError(f"No se encontró {PHP_SCRIPT} en {SCRIPT_DIR}")
    token = uuid.uuid4().hex[:8]
    remote_path = f"{REMOTE_TMP_DIR}/cr_migrar_export_{token}.php"
    sftp.put(str(local_path), remote_path)
    sftp.chmod(remote_path, 0o644)
    return remote_path


def run_migration(
    ssh, server: dict, remote_php: str,
    courses: str = "all", apply_mode: bool = False,
    sudo_password: str | None = None,
) -> dict:
    moodle_path = server["moodle_path"]
    config_path = f"{moodle_path}/config.php"
    web_user = server["web_user"]

    apply_flag = " --apply" if apply_mode else ""
    cmd = (
        f"sudo -u {q(web_user)} php {q(remote_php)}"
        f" --config={q(config_path)}"
        f" --courses={q(courses)}"
        f"{apply_flag}"
    )

    command_logs: list[CommandLog] = []
    step_name = "Export format" + (" (APPLY)" if apply_mode else " (dry-run)")

    log = execute_remote(
        command_logs, ssh,
        step=step_name,
        command=cmd,
        sudo_password=sudo_password,
        timeout=300,
        fail_on_error=False,
    )

    result = parse_php_output(log.stdout)
    if result is None:
        result = parse_php_output(log.stderr)
    if result is None:
        logger.error("No se pudo parsear la salida. exit=%d", log.exit_status)
        logger.error("STDOUT: %s", log.stdout[:2000])
        logger.error("STDERR: %s", log.stderr[:2000])
        raise RuntimeError(
            f"El script PHP no emitió JSON válido. Exit code: {log.exit_status}\n"
            f"STDERR: {log.stderr[:500]}"
        )
    if not result.get("ok"):
        raise RuntimeError(f"Error PHP: {result.get('fatal', result)}")
    return result


def show_dry_run_summary(result: dict) -> None:
    summary = result.get("summary", {})
    courses = result.get("courses", [])

    console.print(f"\n[bold cyan]═══ RESUMEN DRY-RUN (Export format) ═══[/bold cyan]")
    console.print(f"  Tabla iTOP: [yellow]{result.get('rented_table', '?')}[/yellow]")
    console.print(f"  Campo export iTOP: {summary.get('rented_export_field', '?')}")
    console.print(f"  Campo export nuestro: {summary.get('own_export_field', '?')}")
    console.print(f"  Cursos procesados: {summary.get('total_courses', 0)}")
    console.print(f"  Reportes emparejados: {summary.get('reports_matched', 0)}")
    console.print(f"  [green]Exportaciones que se actualizarían: {summary.get('exports_updated', 0)}[/green]")
    console.print(f"  [dim]Ya coinciden: {summary.get('exports_already_match', 0)}[/dim]")
    console.print(f"  [red]Errores: {summary.get('errors', 0)}[/red]")

    for course in courses:
        reports = course.get("reports", [])
        actionable = [r for r in reports if r.get("action") in ("would_update", "updated")]
        if not actionable:
            continue

        console.print(
            f"\n  [bold]Curso {course['courseid']} — {course.get('fullname', '')}[/bold]"
        )

        table = Table(show_header=True, header_style="bold", box=None, padding=(0, 1))
        table.add_column("Reporte", style="cyan")
        table.add_column("Export actual", style="red")
        table.add_column("Export iTOP", style="dim")
        table.add_column("Nuevo export", style="green")
        for r in actionable:
            table.add_row(
                r.get("own_name", "?"),
                r.get("own_export_raw", "?"),
                r.get("rented_export_raw", "?"),
                r.get("new_export", "?"),
            )
        console.print(table)


def show_apply_summary(result: dict) -> None:
    summary = result.get("summary", {})
    console.print(f"\n[bold green]═══ EXPORT FORMAT MIGRADO ═══[/bold green]")
    console.print(f"  Reportes actualizados: {summary.get('exports_updated', 0)}")
    console.print(f"  Ya correctos: {summary.get('exports_already_match', 0)}")
    console.print(f"  Errores: {summary.get('errors', 0)}")


def main():
    console.print("[bold cyan]╔═══════════════════════════════════════════════════════╗[/bold cyan]")
    console.print("[bold cyan]║  Migración Export Format: iTOP → Configurable Reports ║[/bold cyan]")
    console.print("[bold cyan]╚═══════════════════════════════════════════════════════╝[/bold cyan]")

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
        "IDs de cursos (separados por coma, o 'all' para todos)",
        default="all"
    )

    console.print(f"\n[bold]Procesando {len(selected)} servidor(es)...[/bold]\n")

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
            console.print(f"  Script en: {remote_php}")

            sudo_pw = server.get("sudo_password") or (
                settings.get("sudo_password") if server.get("sudo_requires_password") else None
            )

            console.print("  Ejecutando dry-run...")
            dry_result = run_migration(
                ssh, server, remote_php,
                courses=courses_input,
                apply_mode=False,
                sudo_password=sudo_pw,
            )

            show_dry_run_summary(dry_result)

            total_updates = dry_result.get("summary", {}).get("exports_updated", 0)
            if total_updates == 0:
                console.print("\n  [green]No hay cambios de exportación pendientes.[/green]")
                continue

            if safe_confirm(
                f"\n¿Aplicar {total_updates} cambio(s) de formato de exportación en {server['name']}?",
                default=False,
            ):
                console.print("  Aplicando cambios...")
                apply_result = run_migration(
                    ssh, server, remote_php,
                    courses=courses_input,
                    apply_mode=True,
                    sudo_password=sudo_pw,
                )
                show_apply_summary(apply_result)

                log_dir = SCRIPT_DIR / "logs"
                log_dir.mkdir(exist_ok=True)
                log_file = log_dir / f"migrar_export_{server['name']}.json"
                log_file.write_text(
                    json.dumps(apply_result, indent=2, ensure_ascii=False),
                    encoding="utf-8",
                )
                console.print(f"  Resultado guardado en: {log_file}")
            else:
                console.print("  [yellow]Omitido por el usuario.[/yellow]")

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

    console.print("\n[bold green]Proceso completo.[/bold green]")


if __name__ == "__main__":
    main()
