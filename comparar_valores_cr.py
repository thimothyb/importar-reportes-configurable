#!/usr/bin/env python3
"""
Comparativa de valores: iTOP (block_adv_reports_values) vs. bridge legacy
de Configurable Reports.

Para cada curso con datos iTOP en la plataforma seleccionada, compara
los valores cacheados del plugin alquilado con los que devuelve el
bridge legacy de nuestro plugin.

Genera un reporte con porcentaje de coincidencia y detalle de diferencias.

Uso:
    python comparar_valores_cr.py

Se conecta al servidor seleccionado del inventario.json por SSH.
"""

from __future__ import annotations

import json
import posixpath
import sys
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional

from rich.console import Console
from rich.table import Table
from rich.panel import Panel
from rich import box

# Importar utilidades compartidas del proyecto.
sys.path.insert(0, str(Path(__file__).resolve().parent))
from cr_common import (
    CommandLog,
    RemoteCommandError,
    connect_ssh,
    execute_remote,
    is_local_mode,
    load_inventory,
    parse_php_output,
    prompt_execution_mode,
    apply_execution_mode,
    prompt_server_selection,
    safe_cleanup,
    console,
    logger,
)

try:
    from shlex import quote as q
except ImportError:
    from pipes import quote as q

SCRIPT_DIR = Path(__file__).resolve().parent
PHP_COMPARATOR = SCRIPT_DIR / "comparar_valores_cr.php"
INVENTORY_FILE = SCRIPT_DIR / "inventario.json"
REMOTE_TMP_DIR = "/tmp"
OUTPUT_FILE = SCRIPT_DIR / "comparativa_resultado.json"


@dataclass
class CompareResult:
    server_name: str = ""
    host: str = ""
    moodle_path: str = ""
    success: bool = False
    fatal: Optional[str] = None
    summary: Dict[str, Any] = field(default_factory=dict)
    courses: List[Dict[str, Any]] = field(default_factory=list)
    command_logs: List[CommandLog] = field(default_factory=list)


def compare_server(server: Dict[str, Any], courseids: str = "", limit: int = 0) -> CompareResult:
    """Sube comparar_valores_cr.php, lo ejecuta como web_user y parsea el resultado."""
    result = CompareResult(
        server_name=server["name"],
        host=server["host"],
        moodle_path=str(server["moodle_path"]).rstrip("/"),
    )

    config_path = posixpath.join(result.moodle_path, "config.php")
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_php = posixpath.join(REMOTE_TMP_DIR, f"cr_compare_{token}.php")

    ssh = None
    sftp = None
    cleanup_errors: List[str] = []

    try:
        conn_label = "localmente" if is_local_mode(server) else "por SSH"
        console.print(f"[cyan]→ {result.server_name}: conectando {conn_label}...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        console.print(f"[cyan]→ {result.server_name}: subiendo script de comparativa...[/cyan]")
        sftp.put(str(PHP_COMPARATOR), remote_php)
        sftp.chmod(remote_php, 0o644)

        # Construir comando.
        extra_args = ""
        if courseids:
            extra_args += f" --courseids={q(courseids)}"
        if limit > 0:
            extra_args += f" --limit={limit}"

        console.print(f"[cyan]→ {result.server_name}: ejecutando comparativa (puede tardar unos minutos)...[/cyan]")
        command = (
            f"sudo -u {q(web_user)} env HOME=/tmp php {q(remote_php)} "
            f"--config={q(config_path)}{extra_args}"
        )
        run_log = execute_remote(
            result.command_logs, ssh, step="Comparativa", command=command,
            sudo_password=sudo_password, timeout=3600, fail_on_error=False,
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

        result.summary = payload.get("summary", {})
        result.courses = payload.get("courses", [])
        result.success = True
        logger.info(
            "Comparativa OK en %s: %d cursos, %s%% coincidencia",
            result.server_name,
            result.summary.get("total_courses", 0),
            result.summary.get("match_percent", "?"),
        )

    except RemoteCommandError as exc:
        result.fatal = exc.log.stderr.rstrip("\n") or exc.log.stdout.rstrip("\n") or str(exc)
        logger.error("Comparativa FALLÓ en %s: %s", result.server_name, result.fatal)
    except Exception as exc:  # noqa: BLE001
        result.fatal = str(exc)
        logger.error("Comparativa EXCEPCIÓN en %s: %s", result.server_name, result.fatal)
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

    return result


def print_results(results: List[CompareResult]) -> None:
    """Imprime la tabla de resultados."""
    for res in results:
        if res.fatal:
            console.print(f"\n[bold red]✗ {res.server_name}: {res.fatal}[/bold red]")
            continue

        s = res.summary
        pct = s.get("match_percent", 0)
        color = "green" if pct == 100 else ("yellow" if pct >= 90 else "red")

        console.print(Panel(
            f"[bold]Cursos:[/bold] {s.get('total_courses', 0)}  |  "
            f"[bold]Usuarios:[/bold] {s.get('total_users', 0)}  |  "
            f"[bold]Comparaciones:[/bold] {s.get('total_comparisons', 0)}  |  "
            f"[bold]Coincidencias:[/bold] {s.get('total_matches', 0)}  |  "
            f"[bold]Diferencias:[/bold] {s.get('total_diffs', 0)}  |  "
            f"[bold {color}]Match: {pct}%[/bold {color}]",
            title=f"[bold cyan]{res.server_name}[/bold cyan]",
            box=box.ROUNDED,
        ))

        # Tabla por curso.
        table = Table(title="Detalle por curso", box=box.SIMPLE_HEAVY)
        table.add_column("Curso ID", style="cyan", width=8)
        table.add_column("Shortname", width=25)
        table.add_column("Usuarios", justify="right", width=10)
        table.add_column("Comparaciones", justify="right", width=14)
        table.add_column("Coincidencias", justify="right", width=14)
        table.add_column("Diferencias", justify="right", width=12)
        table.add_column("Match %", justify="right", width=10)

        for c in res.courses:
            cpct = c.get("match_percent", 0)
            ccolor = "green" if cpct == 100 else ("yellow" if cpct >= 90 else "red")
            table.add_row(
                str(c["courseid"]),
                c.get("shortname", ""),
                str(c.get("users_compared", 0)),
                str(c.get("total_comparisons", 0)),
                str(c.get("matches", 0)),
                str(c.get("diffs_count", 0)),
                f"[{ccolor}]{cpct}%[/{ccolor}]",
            )

        console.print(table)

        # Mostrar detalle de diffs si hay.
        for c in res.courses:
            diffs = c.get("diffs", [])
            if not diffs:
                continue

            dtable = Table(
                title=f"Diferencias en curso {c['courseid']} ({c.get('shortname', '')})",
                box=box.SIMPLE,
            )
            dtable.add_column("UserID", style="cyan", width=8)
            dtable.add_column("Stat iTOP", width=30)
            dtable.add_column("Valor iTOP", width=25)
            dtable.add_column("Stat type", width=22)
            dtable.add_column("Valor Bridge", width=25)
            dtable.add_column("Razón", width=30)

            for d in diffs[:30]:  # Max 30 en pantalla.
                dtable.add_row(
                    str(d.get("userid", "")),
                    d.get("itop_stat", ""),
                    str(d.get("itop_clean", d.get("itop_value", "")))[:40],
                    d.get("stat_type", ""),
                    str(d.get("bridge_value") or "(null)")[:40],
                    d.get("reason", ""),
                )

            console.print(dtable)

            if c.get("diffs_truncated"):
                console.print(
                    f"  [yellow]... y {c['diffs_total'] - 50} diferencias más (truncado)[/yellow]"
                )


def save_snapshot(results: List[CompareResult]) -> None:
    """Guarda los resultados en JSON."""
    snapshot = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "servers": [],
    }
    for res in results:
        snapshot["servers"].append({
            "server_name": res.server_name,
            "host": res.host,
            "moodle_path": res.moodle_path,
            "success": res.success,
            "fatal": res.fatal,
            "summary": res.summary,
            "courses": res.courses,
        })

    OUTPUT_FILE.write_text(json.dumps(snapshot, indent=2, ensure_ascii=False), encoding="utf-8")
    console.print(f"\n[green]Snapshot guardado en:[/green] {OUTPUT_FILE}")


def main() -> None:
    console.print("[bold cyan]Comparativa · Valores iTOP vs. Bridge Configurable Reports[/bold cyan]\n")
    try:
        if not PHP_COMPARATOR.exists():
            raise FileNotFoundError(f"No se encontró el CLI PHP: {PHP_COMPARATOR}")

        servers, _settings = load_inventory(INVENTORY_FILE)
        mode = prompt_execution_mode()
        apply_execution_mode(servers, mode)
        selected_servers = prompt_server_selection(servers)
        if not selected_servers:
            console.print("[yellow]No se seleccionaron plataformas. Operación cancelada.[/yellow]")
            return

        # Preguntar si limitar a ciertos cursos.
        import questionary
        courseids_input = questionary.text(
            "¿Limitar a course IDs específicos? (separados por coma, o Enter para todos):"
        ).ask()

        limit_input = questionary.text(
            "¿Máximo de cursos a procesar? (Enter para todos):"
        ).ask()

        courseids = courseids_input.strip() if courseids_input else ""
        limit = int(limit_input.strip()) if limit_input and limit_input.strip().isdigit() else 0

        results = [compare_server(server, courseids=courseids, limit=limit) for server in selected_servers]

        print_results(results)
        save_snapshot(results)

    except KeyboardInterrupt:
        console.print("\n[yellow]Operación cancelada.[/yellow]")
    except Exception as exc:  # noqa: BLE001
        console.print(f"[bold red]Error fatal: {exc}[/bold red]")
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == "__main__":
    main()
