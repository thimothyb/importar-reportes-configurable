#!/usr/bin/env python3
"""
Ejecuta el reporte "01 Informe Global" de ambos plugins para una muestra
de cursos y compara los datos resultantes (la tabla que ve el usuario).

Sube ejecutar_informe_global_cr.php al servidor, lo ejecuta como www-data,
y genera un reporte comparativo en consola + archivo JSON.
"""

from __future__ import annotations

import json
import posixpath
import sys
import uuid
from pathlib import Path
from typing import Any, Dict, List

from rich.console import Console
from rich.table import Table
from rich.panel import Panel

from cr_common import (
    apply_execution_mode,
    connect_ssh,
    execute_remote,
    is_local_mode,
    load_inventory,
    parse_php_output,
    prompt_execution_mode,
    prompt_server_selection,
    q,
    safe_cleanup,
    safe_text,
)

SCRIPT_DIR = Path(__file__).resolve().parent
INVENTORY_FILE = SCRIPT_DIR / "inventario.json"
AUDIT_SNAPSHOT_FILE = SCRIPT_DIR / "auditoria_resultado.json"
PHP_EXECUTOR = SCRIPT_DIR / "ejecutar_informe_global_cr.php"
OUTPUT_FILE = SCRIPT_DIR / "ejecucion_informe_global_resultado.json"
REMOTE_TMP_DIR = "/tmp"

# Solo 10 cursos de muestra.
MAX_SAMPLE_COURSES = 10

console = Console()


def get_audit_courseids() -> List[str]:
    """Lee los courseids afectados de auditoria_resultado.json."""
    if not AUDIT_SNAPSHOT_FILE.exists():
        console.print("[red]No existe auditoria_resultado.json. Ejecuta primero auditar_cr.py.[/red]")
        sys.exit(1)
    snapshot = json.loads(AUDIT_SNAPSHOT_FILE.read_text(encoding="utf-8"))
    courseids = []
    for server_entry in snapshot.get("servers", []):
        for cid in server_entry.get("affected_courseids", []):
            if str(cid) not in courseids:
                courseids.append(str(cid))
    if not courseids:
        console.print("[yellow]La auditoría no detectó cursos afectados.[/yellow]")
        sys.exit(0)
    return courseids


def run_execution(server: Dict[str, Any], courseids: List[str]) -> Dict[str, Any]:
    """Sube el PHP, lo ejecuta y devuelve el resultado parseado."""
    moodle_path = str(server["moodle_path"]).rstrip("/")
    config_path = posixpath.join(moodle_path, "config.php")
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_php = posixpath.join(REMOTE_TMP_DIR, f"cr_exec_report_{token}.php")

    ssh = None
    sftp = None
    cleanup_errors: List[str] = []

    try:
        conn_label = "localmente" if is_local_mode(server) else "por SSH"
        console.print(f"[cyan]Conectando {conn_label} a {server['name']}...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        sftp.put(str(PHP_EXECUTOR), remote_php)
        sftp.chmod(remote_php, 0o644)

        courses_arg = ",".join(courseids)
        console.print(f"[cyan]Ejecutando '01 Informe Global' para {len(courseids)} cursos...[/cyan]")

        command = (
            f"sudo -u {q(web_user)} env HOME=/tmp php {q(remote_php)} "
            f"--config={q(config_path)} --courses={q(courses_arg)} --maxrows=200"
        )
        logs = []
        run_log = execute_remote(
            logs, ssh, step="Ejecución informe global", command=command,
            sudo_password=sudo_password, timeout=1800, fail_on_error=False,
        )

        payload = parse_php_output(run_log.stdout)
        if payload is None:
            console.print("[red]No se pudo interpretar la salida PHP.[/red]")
            console.print(f"[dim]exit={run_log.exit_status}[/dim]")
            console.print(f"[dim]stderr={run_log.stderr[:500]}[/dim]")
            console.print(f"[dim]stdout={run_log.stdout[:500]}[/dim]")
            return {"error": "No se pudo parsear la salida"}

        return payload

    except Exception as exc:
        console.print(f"[red]Error: {exc}[/red]")
        return {"error": str(exc)}
    finally:
        if ssh is not None:
            safe_cleanup(ssh, f"rm -f {q(remote_php)}", cleanup_errors, sudo_password=sudo_password)
        if sftp is not None:
            try:
                sftp.close()
            except Exception:
                pass
        if ssh is not None:
            try:
                ssh.close()
            except Exception:
                pass


def print_results(data: Dict[str, Any]) -> None:
    """Imprime los resultados de la ejecución."""
    if not data.get("ok"):
        console.print(f"[red]Error: {data.get('fatal', data.get('error', '?'))}[/red]")
        return

    total_courses = 0
    identical_courses = 0
    different_courses = 0
    error_courses = 0

    for course in data.get("courses", []):
        total_courses += 1

        if course.get("error"):
            console.print(f"\n[red]Curso {course['courseid']}: {course['error']}[/red]")
            error_courses += 1
            continue

        console.print(f"\n[bold cyan]{'='*70}[/bold cyan]")
        console.print(
            f"[bold]Curso {course['courseid']}:[/bold] "
            f"{course.get('fullname', '?')} ({course.get('shortname', '?')})"
        )

        own = course.get("own", {})
        rented = course.get("rented", {})

        if own.get("error"):
            console.print(f"  [red]Propio: {own['error']}[/red]")
        else:
            console.print(
                f"  [green]Propio:[/green] {own.get('row_count', 0)} filas, "
                f"{len(own.get('headers', []))} columnas"
            )
            if own.get("headers"):
                console.print(f"  [dim]Columnas: {', '.join(own['headers'][:10])}{'...' if len(own.get('headers',[])) > 10 else ''}[/dim]")

        if rented.get("error"):
            console.print(f"  [red]Alquilado: {rented['error']}[/red]")
        else:
            console.print(
                f"  [yellow]Alquilado:[/yellow] {rented.get('row_count', 0)} filas, "
                f"{len(rented.get('headers', []))} columnas"
            )
            if rented.get("headers"):
                console.print(f"  [dim]Columnas: {', '.join(rented['headers'][:10])}{'...' if len(rented.get('headers',[])) > 10 else ''}[/dim]")
            if rented.get("note"):
                console.print(f"  [dim]Nota: {rented['note']}[/dim]")

        comp = course.get("comparison")
        if comp:
            if comp.get("data_identical"):
                console.print(f"  [bold green]DATOS IDÉNTICOS[/bold green]")
                identical_courses += 1
            else:
                diff_details = comp.get("different_row_details", [])
                console.print(
                    f"  [bold yellow]DATOS DIFERENTES[/bold yellow] — "
                    f"{comp.get('identical_rows', '?')}/{comp.get('own_rows', '?')} filas iguales, "
                    f"{len(diff_details)} filas distintas"
                )
                different_courses += 1

                # Mostrar detalle de las primeras filas diferentes.
                for rowdiff in diff_details[:5]:
                    console.print(f"\n  [yellow]Fila {rowdiff['row']}:[/yellow]")
                    for celldiff in rowdiff.get("diffs", [])[:8]:
                        console.print(
                            f"    {celldiff['column']}: "
                            f"propio=[cyan]{str(celldiff['own'])[:60]}[/cyan] | "
                            f"alquilado=[magenta]{str(celldiff['rented'])[:60]}[/magenta]"
                        )
                if len(diff_details) > 5:
                    console.print(f"  [dim]... y {len(diff_details) - 5} filas más con diferencias[/dim]")

            if not comp.get("headers_identical"):
                console.print(f"  [yellow]Nota: las cabeceras son diferentes[/yellow]")
        else:
            if not own.get("error") and not rented.get("error"):
                # Ambos ejecutaron pero sin comparación (diferente # de filas).
                console.print(
                    f"  [yellow]No se pudo comparar: "
                    f"{own.get('row_count', '?')} vs {rented.get('row_count', '?')} filas[/yellow]"
                )
                different_courses += 1
            else:
                error_courses += 1

    # Resumen.
    console.print(f"\n")
    console.print(Panel(
        f"Cursos evaluados:     [bold]{total_courses}[/bold]\n"
        f"Datos idénticos:      [bold green]{identical_courses}[/bold green]\n"
        f"Datos diferentes:     [bold yellow]{different_courses}[/bold yellow]\n"
        f"Con errores:          [red]{error_courses}[/red]",
        title="[bold cyan]Resumen ejecución '01 Informe Global'[/bold cyan]",
        border_style="cyan",
    ))


def main() -> None:
    console.print("[bold cyan]Ejecutor de '01 Informe Global' · Propio vs. Alquilado[/bold cyan]\n")

    try:
        servers, settings = load_inventory(INVENTORY_FILE)
        exec_mode = prompt_execution_mode()
        apply_execution_mode(servers, exec_mode)

        selected_servers = prompt_server_selection(servers)
        if not selected_servers:
            console.print("[yellow]No se seleccionaron plataformas.[/yellow]")
            return

        all_courseids = get_audit_courseids()

        # Tomar muestra.
        sample = all_courseids[:MAX_SAMPLE_COURSES]
        console.print(
            f"[green]Muestra de {len(sample)} cursos (de {len(all_courseids)} totales):[/green] "
            f"{', '.join(sample)}"
        )

        server = selected_servers[0]
        result = run_execution(server, sample)

        # Guardar resultado completo.
        OUTPUT_FILE.write_text(json.dumps(result, indent=2, ensure_ascii=False), encoding="utf-8")
        console.print(f"\n[green]Resultado guardado en:[/green] {OUTPUT_FILE}")

        # Mostrar en consola.
        print_results(result)

    except KeyboardInterrupt:
        console.print("\n[yellow]Cancelado.[/yellow]")
    except Exception as exc:
        console.print(f"[bold red]Error fatal: {exc}[/bold red]")
        sys.exit(1)


if __name__ == "__main__":
    main()