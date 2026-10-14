#!/usr/bin/env python3
"""
Migrar configuraciones de items seleccionados en columnas userstats
desde iTOP → userstatsadvanced (Configurable Reports).

Lee los items seleccionados (cuestionarios, SCORMs, tareas, secciones,
actividades/recursos) de las columnas userstats del plugin alquilado (iTOP)
y actualiza las columnas userstatsadvanced de nuestro plugin propio con
los cmids equivalentes.

Flujo:
  1. Sube migrar_userstats_itop_cr.php al servidor.
  2. Lo ejecuta con --courses=all (o ids específicos) en modo dry-run.
  3. Muestra el resumen de cambios propuestos.
  4. Si el usuario confirma, lo vuelve a ejecutar con --apply.

Uso:
  python migrar_userstats_itop_cr.py

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

# Agregar el directorio actual al path para poder importar cr_common.
sys.path.insert(0, str(Path(__file__).resolve().parent))

from cr_common import (
    CommandLog,
    RemoteCommandError,
    RESULT_RE,
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
    safe_select,
    safe_text,
)

REMOTE_TMP_DIR = "/tmp"
PHP_SCRIPT = "migrar_userstats_itop_cr.php"
SCRIPT_DIR = Path(__file__).resolve().parent


def upload_php_script(ssh, sftp, server: dict) -> str:
    """Sube el script PHP al servidor y devuelve su ruta remota."""
    local_path = SCRIPT_DIR / PHP_SCRIPT
    if not local_path.exists():
        raise FileNotFoundError(f"No se encontró {PHP_SCRIPT} en {SCRIPT_DIR}")

    token = uuid.uuid4().hex[:8]
    remote_path = f"{REMOTE_TMP_DIR}/cr_migrar_userstats_{token}.php"
    sftp.put(str(local_path), remote_path)
    sftp.chmod(remote_path, 0o644)
    return remote_path


def run_migration(
    ssh,
    server: dict,
    remote_php: str,
    courses: str = "all",
    apply_mode: bool = False,
    sudo_password: str | None = None,
) -> dict:
    """Ejecuta el script PHP de migración y devuelve el resultado JSON."""
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
    step_name = "Migración userstats" + (" (APPLY)" if apply_mode else " (dry-run)")

    log = execute_remote(
        command_logs, ssh,
        step=step_name,
        command=cmd,
        sudo_password=sudo_password,
        timeout=600,
        fail_on_error=False,
    )

    result = parse_php_output(log.stdout)
    if result is None:
        result = parse_php_output(log.stderr)

    if result is None:
        logger.error("No se pudo parsear la salida del script PHP. exit=%d", log.exit_status)
        logger.error("STDOUT (primeros 2000 chars): %s", log.stdout[:2000])
        logger.error("STDERR (primeros 2000 chars): %s", log.stderr[:2000])
        raise RuntimeError(
            f"El script PHP no emitió JSON válido. Exit code: {log.exit_status}\n"
            f"STDERR: {log.stderr[:500]}"
        )

    if not result.get("ok"):
        raise RuntimeError(f"El script PHP reportó error: {result.get('fatal', result)}")

    return result


def show_dry_run_summary(result: dict) -> None:
    """Muestra un resumen legible del dry-run."""
    summary = result.get("summary", {})
    courses = result.get("courses", [])

    console.print(f"\n[bold cyan]═══ RESUMEN DRY-RUN (Userstats selections) ═══[/bold cyan]")
    console.print(f"  Tabla iTOP detectada: [yellow]{result.get('rented_table', '?')}[/yellow]")
    console.print(f"  Cursos procesados: {summary.get('total_courses', 0)}")
    console.print(f"  Reportes emparejados: {summary.get('reports_matched', 0)}")
    console.print(f"  Columnas emparejadas: {summary.get('columns_matched', 0)}")
    console.print(f"  Columnas con selections iTOP: {summary.get('columns_with_itop_selections', 0)}")
    console.print(f"  [green]Columnas que se migrarían: {summary.get('columns_migrated', 0)}[/green]")
    console.print(f"  [dim]Columnas ya coinciden: {summary.get('columns_already_match', 0)}[/dim]")
    console.print(f"  [dim]Sin selections en iTOP: {summary.get('columns_no_selections', 0)}[/dim]")
    console.print(f"  [red]Errores: {summary.get('errors', 0)}[/red]")

    # Detalle por curso/reporte.
    for course in courses:
        if course.get("error"):
            console.print(f"\n  [red]Curso {course['courseid']}: {course['error']}[/red]")
            continue
        reports = course.get("reports", [])
        if not reports:
            continue

        console.print(
            f"\n  [bold]Curso {course['courseid']} — "
            f"{course.get('fullname', '')}[/bold]"
        )

        for rep in reports:
            migrations = rep.get("column_migrations", [])
            if not migrations:
                continue

            actionable = [
                m for m in migrations
                if m.get("action") in ("would_migrate", "migrated")
            ]
            if not actionable:
                # Mostrar resumen breve si no hay acciones.
                no_sel = sum(1 for m in migrations if m.get("action") == "no_itop_selections")
                already = sum(1 for m in migrations if m.get("action") == "already_match")
                if no_sel or already:
                    console.print(
                        f"    Reporte: {rep['own_name']} (id={rep['own_id']}) "
                        f"— sin selections: {no_sel}, ya coinciden: {already}"
                    )
                continue

            console.print(
                f"    Reporte: {rep['own_name']} (id={rep['own_id']}) ↔ "
                f"{rep.get('rented_name', '?')} (id={rep.get('rented_id', '?')})"
            )
            console.print(
                f"    Cols userstats nuestras: {rep.get('own_col_count', 0)}, "
                f"Cols userstats iTOP: {rep.get('itop_col_count', 0)}, "
                f"Migraciones: {len(actionable)}"
            )

            # Tabla de cambios.
            table = Table(show_header=True, header_style="bold", box=None, padding=(0, 1))
            table.add_column("Cabecera", style="cyan")
            table.add_column("CMIDs actuales", style="red")
            table.add_column("CMIDs nuevos (de iTOP)", style="green")
            table.add_column("Selectores iTOP", style="dim")
            for m in actionable:
                existing = m.get("existing_cmids", [])
                target = m.get("target_cmids", [])
                selectors = ", ".join(
                    f"{s['field']}({s['count']})"
                    for s in m.get("itop_selections_detail", [])
                )
                table.add_row(
                    m.get("own_header", "?"),
                    str(existing) if existing else "(ninguno)",
                    str(target),
                    selectors,
                )
            console.print(table)


def show_apply_summary(result: dict) -> None:
    """Muestra el resumen post-apply."""
    summary = result.get("summary", {})
    console.print(f"\n[bold green]═══ MIGRACIÓN USERSTATS APLICADA ═══[/bold green]")
    console.print(f"  Reportes actualizados en BD: {summary.get('reports_updated_in_db', 0)}")
    console.print(f"  Columnas migradas: {summary.get('columns_migrated', 0)}")
    console.print(f"  Columnas ya correctas: {summary.get('columns_already_match', 0)}")
    console.print(f"  Errores: {summary.get('errors', 0)}")


def main():
    console.print("[bold cyan]╔═══════════════════════════════════════════════════════════╗[/bold cyan]")
    console.print("[bold cyan]║  Migración Userstats Selections: iTOP → Conf. Reports    ║[/bold cyan]")
    console.print("[bold cyan]╚═══════════════════════════════════════════════════════════╝[/bold cyan]")

    # Cargar inventario.
    inv_path = SCRIPT_DIR / "inventario.json"
    if not inv_path.exists():
        console.print(f"[red]No se encontró {inv_path}[/red]")
        sys.exit(1)

    servers, settings = load_inventory(inv_path)

    # Modo de ejecución.
    mode = prompt_execution_mode()
    apply_execution_mode(servers, mode)

    # Seleccionar servidores.
    selected = prompt_server_selection(servers)
    if not selected:
        console.print("[yellow]No se seleccionaron servidores.[/yellow]")
        sys.exit(0)

    # Cursos.
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

            # 1. Subir script PHP.
            console.print("  Subiendo script PHP...")
            remote_php = upload_php_script(ssh, sftp, server)
            console.print(f"  Script en: {remote_php}")

            # 2. Dry-run.
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

            # Verificar si hay cambios.
            total_migrations = dry_result.get("summary", {}).get("columns_migrated", 0)
            if total_migrations == 0:
                console.print("\n  [green]No hay migraciones pendientes para este servidor.[/green]")
                continue

            # 3. Confirmar y aplicar.
            if safe_confirm(
                f"\n¿Aplicar {total_migrations} migración(es) de selections en {server['name']}?",
                default=False,
            ):
                console.print("  Aplicando migraciones...")
                apply_result = run_migration(
                    ssh, server, remote_php,
                    courses=courses_input,
                    apply_mode=True,
                    sudo_password=sudo_pw,
                )
                show_apply_summary(apply_result)

                # Guardar resultado en log.
                log_dir = SCRIPT_DIR / "logs"
                log_dir.mkdir(exist_ok=True)
                log_file = log_dir / f"migrar_userstats_{server['name']}.json"
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
            # Limpiar script remoto.
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
