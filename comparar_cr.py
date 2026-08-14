#!/usr/bin/env python3
"""
Compara los datos de ambos plugins de reportes:
  - Propio:    block_configurable_reports
  - Alquilado: block_advanced_reports

Sube comparar_reportes_cr.php al servidor, lo ejecuta como www-data,
y genera un reporte comparativo en consola + archivo JSON.

Usa los cursos de auditoria_resultado.json (los mismos de la migración).
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
PHP_COMPARATOR = SCRIPT_DIR / "comparar_reportes_cr.php"
OUTPUT_FILE = SCRIPT_DIR / "comparacion_resultado.json"
REMOTE_TMP_DIR = "/tmp"

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


def run_comparison(server: Dict[str, Any], courseids: List[str]) -> Dict[str, Any]:
    """Sube el PHP, lo ejecuta y devuelve el resultado parseado."""
    moodle_path = str(server["moodle_path"]).rstrip("/")
    config_path = posixpath.join(moodle_path, "config.php")
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_php = posixpath.join(REMOTE_TMP_DIR, f"cr_compare_{token}.php")

    ssh = None
    sftp = None
    cleanup_errors: List[str] = []

    try:
        conn_label = "localmente" if is_local_mode(server) else "por SSH"
        console.print(f"[cyan]Conectando {conn_label} a {server['name']}...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        # Subir PHP
        sftp.put(str(PHP_COMPARATOR), remote_php)
        sftp.chmod(remote_php, 0o644)

        courses_arg = ",".join(courseids)
        console.print(f"[cyan]Ejecutando comparación para cursos: {courses_arg}...[/cyan]")

        command = (
            f"sudo -u {q(web_user)} env HOME=/tmp php {q(remote_php)} "
            f"--config={q(config_path)} --courses={q(courses_arg)} --maxrows=50"
        )
        logs = []
        run_log = execute_remote(
            logs, ssh, step="Comparación", command=command,
            sudo_password=sudo_password, timeout=1800, fail_on_error=False,
        )

        payload = parse_php_output(run_log.stdout)
        if payload is None:
            console.print(f"[red]No se pudo interpretar la salida PHP.[/red]")
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


def print_comparison(data: Dict[str, Any]) -> None:
    """Imprime el comparativo en consola con tablas Rich."""
    if not data.get("ok"):
        console.print(f"[red]Error: {data.get('fatal', data.get('error', '?'))}[/red]")
        return

    rented_table = data.get("rented_table_found")
    console.print(f"\n[bold]Tabla del plugin alquilado:[/bold] {rented_table or '[red]no encontrada[/red]'}")
    console.print(f"[bold]Plugin propio presente:[/bold] {'sí' if data.get('own_plugin_present') else 'no'}\n")

    for course in data.get("courses", []):
        if course.get("error"):
            console.print(f"[red]Curso {course['courseid']}: {course['error']}[/red]")
            continue

        console.print(f"\n[bold cyan]{'='*60}[/bold cyan]")
        console.print(f"[bold]Curso {course['courseid']}:[/bold] {course.get('fullname', '?')} ({course.get('shortname', '?')})")

        own_reports = course.get("own_reports", [])
        rented_reports = course.get("rented_reports", [])

        console.print(f"  Reportes propios (configurable_reports): [green]{len(own_reports)}[/green]")
        console.print(f"  Reportes alquilado (advanced_reports):   [yellow]{len(rented_reports)}[/yellow]")

        if course.get("rented_note"):
            console.print(f"  [yellow]{course['rented_note']}[/yellow]")
        if course.get("rented_table_error"):
            console.print(f"  [red]Error tabla alquilado: {course['rented_table_error']}[/red]")

        # Tabla resumen de reportes propios
        if own_reports:
            table = Table(title="Reportes PROPIOS (block_configurable_reports)")
            table.add_column("ID", justify="right")
            table.add_column("Nombre")
            table.add_column("Tipo")
            table.add_column("Filas", justify="right")
            table.add_column("Estado")

            for r in own_reports:
                rows = r.get("data", {}).get("row_count", 0) if r.get("data") else 0
                error = r.get("data", {}).get("error") if r.get("data") else None
                estado = f"[red]{error[:40]}[/red]" if error else f"[green]{rows} filas[/green]"
                if not r.get("sql"):
                    estado = "[dim]sin SQL[/dim]"
                table.add_row(str(r["id"]), r["name"], r.get("type", "?"), str(rows), estado)
            console.print(table)

        # Tabla resumen de reportes alquilados
        if rented_reports:
            table = Table(title="Reportes ALQUILADO (block_advanced_reports)")
            table.add_column("ID", justify="right")
            table.add_column("Nombre")
            table.add_column("Tipo")
            table.add_column("Filas", justify="right")
            table.add_column("Estado")

            for r in rented_reports:
                rows = r.get("data", {}).get("row_count", 0) if r.get("data") else 0
                error = r.get("data", {}).get("error") if r.get("data") else None
                estado = f"[red]{error[:40]}[/red]" if error else f"[green]{rows} filas[/green]"
                if not r.get("sql"):
                    estado = "[dim]sin SQL / cifrado[/dim]"
                table.add_row(str(r["id"]), r["name"], r.get("type", "?"), str(rows), estado)
            console.print(table)

        # Comparar datos de reportes con el mismo nombre
        own_by_name = {r["name"]: r for r in own_reports}
        rented_by_name = {r["name"]: r for r in rented_reports}
        common_names = set(own_by_name.keys()) & set(rented_by_name.keys())

        if common_names:
            console.print(f"\n  [bold]Reportes con el mismo nombre (comparación directa):[/bold]")
            for name in sorted(common_names):
                own_r = own_by_name[name]
                rented_r = rented_by_name[name]

                own_data = own_r.get("data") or {}
                rented_data = rented_r.get("data") or {}

                own_rows = own_data.get("rows", [])
                rented_rows = rented_data.get("rows", [])

                match = "?"
                if own_data.get("error") or rented_data.get("error"):
                    match = "[yellow]error en ejecución[/yellow]"
                elif own_rows == rented_rows:
                    match = "[bold green]IDÉNTICOS[/bold green]"
                elif len(own_rows) == len(rented_rows):
                    match = "[yellow]misma cantidad, datos diferentes[/yellow]"
                else:
                    match = f"[red]DIFERENTE ({len(own_rows)} vs {len(rented_rows)} filas)[/red]"

                console.print(f"    • {name}: {match}")
        else:
            if own_reports and rented_reports:
                console.print("\n  [yellow]No hay reportes con nombre coincidente entre ambos plugins.[/yellow]")
                console.print("  Nombres propios: " + ", ".join(r["name"] for r in own_reports))
                console.print("  Nombres alquilado: " + ", ".join(r["name"] for r in rented_reports))


def main() -> None:
    console.print("[bold cyan]Comparador de reportes · Propio vs. Alquilado[/bold cyan]\n")

    try:
        servers, settings = load_inventory(INVENTORY_FILE)
        exec_mode = prompt_execution_mode()
        apply_execution_mode(servers, exec_mode)

        selected_servers = prompt_server_selection(servers)
        if not selected_servers:
            console.print("[yellow]No se seleccionaron plataformas.[/yellow]")
            return

        courseids = get_audit_courseids()
        console.print(f"[green]Cursos a comparar (de la auditoría):[/green] {', '.join(courseids)}")

        server = selected_servers[0]
        result = run_comparison(server, courseids)

        # Guardar resultado completo
        OUTPUT_FILE.write_text(json.dumps(result, indent=2, ensure_ascii=False), encoding="utf-8")
        console.print(f"\n[green]Resultado guardado en:[/green] {OUTPUT_FILE}")

        # Mostrar comparativo en consola
        print_comparison(result)

    except KeyboardInterrupt:
        console.print("\n[yellow]Cancelado.[/yellow]")
    except Exception as exc:
        console.print(f"[bold red]Error fatal: {exc}[/bold red]")
        sys.exit(1)


if __name__ == "__main__":
    main()
