#!/usr/bin/env python3
"""
Compara el campo `components` deserializado de reportes con nombre
coincidente entre ambos plugins:
  - Propio:    block_configurable_reports
  - Alquilado: block_advanced_reports

Sube comparar_componentes_cr.php al servidor, lo ejecuta como www-data,
y genera un reporte detallado de diferencias en consola + archivo JSON.

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
PHP_COMPARATOR = SCRIPT_DIR / "comparar_componentes_cr.php"
OUTPUT_FILE = SCRIPT_DIR / "comparacion_componentes_resultado.json"
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


def run_comparison_batch(ssh, remote_php: str, config_path: str, web_user: str,
                         courseids: List[str], sudo_password) -> Dict[str, Any]:
    """Ejecuta el PHP para un lote de cursos y devuelve el resultado."""
    courses_arg = ",".join(courseids)
    command = (
        f"sudo -u {q(web_user)} env HOME=/tmp php {q(remote_php)} "
        f"--config={q(config_path)} --courses={q(courses_arg)}"
    )
    logs = []
    run_log = execute_remote(
        logs, ssh, step="Comparación componentes", command=command,
        sudo_password=sudo_password, timeout=1800, fail_on_error=False,
    )

    payload = parse_php_output(run_log.stdout)
    if payload is None:
        console.print(f"[red]  No se pudo interpretar la salida PHP para cursos {courses_arg[:60]}...[/red]")
        console.print(f"[dim]  exit={run_log.exit_status}[/dim]")
        console.print(f"[dim]  stderr={run_log.stderr[:300]}[/dim]")
        console.print(f"[dim]  stdout={run_log.stdout[:300]}[/dim]")
        return None
    return payload


BATCH_SIZE = 15  # Cursos por lote para evitar exceder memoria PHP


def run_comparison(server: Dict[str, Any], courseids: List[str]) -> Dict[str, Any]:
    """Sube el PHP, lo ejecuta en lotes y fusiona los resultados."""
    moodle_path = str(server["moodle_path"]).rstrip("/")
    config_path = posixpath.join(moodle_path, "config.php")
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_php = posixpath.join(REMOTE_TMP_DIR, f"cr_comp_components_{token}.php")

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

        # Dividir en lotes
        batches = [courseids[i:i + BATCH_SIZE] for i in range(0, len(courseids), BATCH_SIZE)]
        console.print(
            f"[cyan]Comparando componentes para {len(courseids)} cursos "
            f"en {len(batches)} lotes de hasta {BATCH_SIZE}...[/cyan]"
        )

        # Resultado fusionado
        merged = {
            "ok": True,
            "rented_table": None,
            "summary": {
                "total_courses": 0,
                "total_pairs": 0,
                "identical_pairs": 0,
                "different_pairs": 0,
                "own_only": 0,
                "rented_only": 0,
                "deserialize_errors": 0,
            },
            "courses": [],
        }

        for idx, batch in enumerate(batches, 1):
            console.print(f"[cyan]  Lote {idx}/{len(batches)} ({len(batch)} cursos)...[/cyan]")
            payload = run_comparison_batch(
                ssh, remote_php, config_path, web_user, batch, sudo_password,
            )
            if payload is None:
                continue
            if not payload.get("ok"):
                console.print(f"[red]  Lote {idx} error: {payload.get('fatal', '?')}[/red]")
                continue

            # Fusionar
            if payload.get("rented_table"):
                merged["rented_table"] = payload["rented_table"]
            for key in merged["summary"]:
                merged["summary"][key] += payload.get("summary", {}).get(key, 0)
            merged["courses"].extend(payload.get("courses", []))

        return merged

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
    """Imprime el comparativo detallado en consola."""
    if not data.get("ok"):
        console.print(f"[red]Error: {data.get('fatal', data.get('error', '?'))}[/red]")
        return

    # Resumen global
    summary = data.get("summary", {})
    console.print(Panel(
        f"Cursos analizados: [bold]{summary.get('total_courses', 0)}[/bold]\n"
        f"Pares comparados:  [bold]{summary.get('total_pairs', 0)}[/bold]\n"
        f"Idénticos:         [bold green]{summary.get('identical_pairs', 0)}[/bold green]\n"
        f"Diferentes:        [bold red]{summary.get('different_pairs', 0)}[/bold red]\n"
        f"Solo en propio:    [yellow]{summary.get('own_only', 0)}[/yellow]\n"
        f"Solo en alquilado: [yellow]{summary.get('rented_only', 0)}[/yellow]\n"
        f"Errores deserial.: [dim]{summary.get('deserialize_errors', 0)}[/dim]",
        title="[bold cyan]Resumen de comparación de componentes[/bold cyan]",
        border_style="cyan",
    ))

    for course in data.get("courses", []):
        if course.get("error"):
            console.print(f"\n[red]Curso {course['courseid']}: {course['error']}[/red]")
            continue

        console.print(f"\n[bold cyan]{'='*60}[/bold cyan]")
        console.print(
            f"[bold]Curso {course['courseid']}:[/bold] "
            f"{course.get('fullname', '?')} ({course.get('shortname', '?')})"
        )

        pairs = course.get("pairs", [])
        own_only = course.get("own_only", [])
        rented_only = course.get("rented_only", [])

        if pairs:
            table = Table(title="Reportes emparejados por nombre")
            table.add_column("Propio ID", justify="right")
            table.add_column("Alquilado ID", justify="right")
            table.add_column("Nombre")
            table.add_column("Estado")
            table.add_column("Difs.", justify="right")

            for pair in pairs:
                if pair.get("identical"):
                    estado = "[bold green]IDÉNTICO[/bold green]"
                    difs = "0"
                elif pair.get("status") == "error_deserializar_propio":
                    estado = "[red]error propio[/red]"
                    difs = "—"
                elif pair.get("status") == "error_deserializar_alquilado":
                    estado = "[red]error alquilado[/red]"
                    difs = "—"
                else:
                    estado = "[yellow]DIFERENTE[/yellow]"
                    difs = str(pair.get("diff_count", "?"))

                name_display = pair["own_name"]
                if pair["own_name"] != pair["rented_name"]:
                    name_display += f" ↔ {pair['rented_name']}"

                table.add_row(
                    str(pair["own_id"]), str(pair["rented_id"]),
                    name_display[:50], estado, difs,
                )
            console.print(table)

            # Mostrar detalles de diferencias
            for pair in pairs:
                if pair.get("identical") or not pair.get("diffs"):
                    continue

                console.print(f"\n  [bold yellow]Diferencias en:[/bold yellow] {pair['own_name']}")
                for diff in pair["diffs"][:15]:  # Limitar a 15 diferencias por reporte
                    path = diff.get("path", "?")
                    dtype = diff.get("type", "?")

                    if dtype == "solo_en_propio":
                        console.print(f"    [green]+ {path}[/green]: {diff.get('own_value', '')}")
                    elif dtype == "solo_en_alquilado":
                        console.print(f"    [red]- {path}[/red]: {diff.get('rented_value', '')}")
                    else:
                        console.print(
                            f"    [yellow]~ {path}[/yellow]: "
                            f"propio=[cyan]{diff.get('own_value', '')}[/cyan] | "
                            f"alquilado=[magenta]{diff.get('rented_value', '')}[/magenta]"
                        )

                if len(pair.get("diffs", [])) > 15:
                    console.print(f"    [dim]... y {len(pair['diffs']) - 15} diferencia(s) más[/dim]")

                # Mostrar diferencias de metadatos
                if pair.get("meta_diffs"):
                    for md in pair["meta_diffs"]:
                        console.print(
                            f"    [dim]meta.{md['field']}:[/dim] "
                            f"propio={md['own']} | alquilado={md['rented']}"
                        )

        if own_only:
            console.print(f"\n  [yellow]Solo en propio ({len(own_only)}):[/yellow]")
            for r in own_only:
                console.print(f"    • {r['name']} (id={r['id']}, tipo={r.get('type', '?')})")

        if rented_only:
            console.print(f"\n  [yellow]Solo en alquilado ({len(rented_only)}):[/yellow]")
            for r in rented_only:
                console.print(f"    • {r['name']} (id={r['id']}, tipo={r.get('type', '?')})")


def main() -> None:
    console.print("[bold cyan]Comparador de componentes · Propio vs. Alquilado[/bold cyan]\n")

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