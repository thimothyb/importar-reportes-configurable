#!/usr/bin/env python3
"""
Reparar reportes rotos por la migración de stats.

La migración original usó cr_unserialize() que hace (array) cast en el
nivel superior, corrompiendo la estructura de datos. Este script repara
los reportes usando raw_deserialize/raw_serialize que preservan los tipos.

Flujo:
  1. Sube reparar_stats_cr.php al servidor.
  2. Lo ejecuta con --reportids=id1,id2 en modo dry-run.
  3. Muestra el resumen de cambios propuestos.
  4. Si el usuario confirma, lo vuelve a ejecutar con --apply.

Uso:
  python3 reparar_stats_cr.py

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
PHP_SCRIPT = "reparar_stats_cr.php"
SCRIPT_DIR = Path(__file__).resolve().parent


def upload_php_script(ssh, sftp, server: dict) -> str:
    local_path = SCRIPT_DIR / PHP_SCRIPT
    if not local_path.exists():
        raise FileNotFoundError(f"No se encontró {PHP_SCRIPT} en {SCRIPT_DIR}")
    token = uuid.uuid4().hex[:8]
    remote_path = f"{REMOTE_TMP_DIR}/cr_reparar_stats_{token}.php"
    sftp.put(str(local_path), remote_path)
    sftp.chmod(remote_path, 0o644)
    return remote_path


def run_repair(
    ssh, server: dict, remote_php: str,
    report_ids: str, apply_mode: bool = False,
    sudo_password: str | None = None,
) -> dict:
    moodle_path = server["moodle_path"]
    config_path = f"{moodle_path}/config.php"
    web_user = server["web_user"]

    apply_flag = " --apply" if apply_mode else ""
    cmd = (
        f"sudo -u {q(web_user)} php {q(remote_php)}"
        f" --config={q(config_path)}"
        f" --reportids={q(report_ids)}"
        f"{apply_flag}"
    )

    command_logs: list[CommandLog] = []
    step_name = "Reparar stats" + (" (APPLY)" if apply_mode else " (dry-run)")

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
    reports = result.get("reports", [])

    console.print(f"\n[bold cyan]═══ RESUMEN DRY-RUN (Reparación stats) ═══[/bold cyan]")
    console.print(f"  Tabla iTOP: [yellow]{result.get('rented_table', '?')}[/yellow]")
    console.print(f"  Reportes procesados: {summary.get('total_reports', 0)}")
    console.print(f"  [green]Reportes a reparar: {summary.get('reports_repaired', 0)}[/green]")
    console.print(f"  [dim]Ya correctos: {summary.get('reports_already_ok', 0)}[/dim]")
    console.print(f"  [red]Errores: {summary.get('errors', 0)}[/red]")

    for rep in reports:
        if rep.get("error"):
            console.print(f"\n  [red]Reporte {rep.get('id', '?')}: {rep['error']}[/red]")
            continue

        action = rep.get("action", "")
        if action in ("would_repair", "repaired"):
            console.print(
                f"\n  [bold]Reporte {rep['id']} — {rep.get('name', '?')}[/bold]"
            )

            table = Table(show_header=True, header_style="bold", box=None, padding=(0, 1))
            table.add_column("Campo", style="cyan")
            table.add_column("Detalle", style="green")

            columns = rep.get("columns_detail", [])
            for col in columns:
                stat = col.get("stat", "")
                header = col.get("header", "?")
                status = col.get("status", "")
                if status == "migrated":
                    table.add_row(header, f"[green]{stat}[/green] (migrado de iTOP)")
                elif status == "preserved":
                    table.add_row(header, f"[dim]{stat}[/dim] (preservado)")
                elif status == "error":
                    table.add_row(header, f"[red]ERROR: {col.get('error', '?')}[/red]")

            if columns:
                console.print(table)

            console.print(f"  Acción: [yellow]{action}[/yellow]")
        elif action == "already_ok":
            console.print(f"  Reporte {rep['id']} — {rep.get('name', '?')}: [green]ya correcto[/green]")


def show_apply_summary(result: dict) -> None:
    summary = result.get("summary", {})
    console.print(f"\n[bold green]═══ REPORTES REPARADOS ═══[/bold green]")
    console.print(f"  Reportes reparados: {summary.get('reports_repaired', 0)}")
    console.print(f"  Ya correctos: {summary.get('reports_already_ok', 0)}")
    console.print(f"  Errores: {summary.get('errors', 0)}")


def main():
    console.print("[bold cyan]╔═══════════════════════════════════════════════════════╗[/bold cyan]")
    console.print("[bold cyan]║  Reparación de Stats: reportes rotos por migración   ║[/bold cyan]")
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

    report_ids_input = safe_text(
        "IDs de reportes a reparar (separados por coma)",
        default=""
    )
    if not report_ids_input.strip():
        console.print("[red]Debes indicar al menos un ID de reporte.[/red]")
        sys.exit(1)

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
            dry_result = run_repair(
                ssh, server, remote_php,
                report_ids=report_ids_input,
                apply_mode=False,
                sudo_password=sudo_pw,
            )

            show_dry_run_summary(dry_result)

            total_repairs = dry_result.get("summary", {}).get("reports_repaired", 0)
            if total_repairs == 0:
                console.print("\n  [green]No hay reportes que reparar.[/green]")
                continue

            if safe_confirm(
                f"\n¿Aplicar reparación de {total_repairs} reporte(s) en {server['name']}?",
                default=False,
            ):
                console.print("  Aplicando reparación...")
                apply_result = run_repair(
                    ssh, server, remote_php,
                    report_ids=report_ids_input,
                    apply_mode=True,
                    sudo_password=sudo_pw,
                )
                show_apply_summary(apply_result)

                log_dir = SCRIPT_DIR / "logs"
                log_dir.mkdir(exist_ok=True)
                log_file = log_dir / f"reparar_stats_{server['name']}.json"
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
