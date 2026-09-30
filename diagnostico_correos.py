#!/usr/bin/env python3
"""
Diagnóstico: analiza cómo iTOP calcula "correos" para un usuario/curso.
Sube diagnostico_correos.php al servidor por SSH y lo ejecuta.

Uso:
    python diagnostico_correos.py [--userid=2430] [--courseid=450]
"""

from __future__ import annotations

import json
import posixpath
import sys
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from cr_common import (
    connect_ssh,
    execute_remote,
    is_local_mode,
    load_inventory,
    parse_php_output,
    prompt_server_selection,
    console,
    logger,
)

try:
    from shlex import quote as q
except ImportError:
    from pipes import quote as q

SCRIPT_DIR = Path(__file__).resolve().parent
PHP_SCRIPT = SCRIPT_DIR / "diagnostico_correos.php"
INVENTORY_FILE = SCRIPT_DIR / "inventario.json"
REMOTE_TMP_DIR = "/tmp"


def main():
    # Parse args
    userid = 2430
    courseid = 450
    for arg in sys.argv[1:]:
        if arg.startswith("--userid="):
            userid = int(arg.split("=", 1)[1])
        elif arg.startswith("--courseid="):
            courseid = int(arg.split("=", 1)[1])

    console.print(f"[bold cyan]Diagnóstico de correos: userid={userid}, courseid={courseid}[/bold cyan]")

    inventory = load_inventory(INVENTORY_FILE)
    servers = prompt_server_selection(inventory["servers"])
    if not servers:
        console.print("[red]No se seleccionó servidor.[/red]")
        return

    server = servers[0]
    moodle_path = str(server["moodle_path"]).rstrip("/")
    config_path = posixpath.join(moodle_path, "config.php")
    web_user = str(server["web_user"])
    sudo_password = server.get("sudo_password") if server.get("sudo_requires_password") else None

    token = uuid.uuid4().hex
    remote_php = posixpath.join(REMOTE_TMP_DIR, f"cr_diag_{token}.php")

    command_logs = []
    ssh = None
    sftp = None

    try:
        conn_label = "localmente" if is_local_mode(server) else "por SSH"
        console.print(f"[cyan]→ Conectando {conn_label} a {server['name']}...[/cyan]")
        ssh = connect_ssh(server)
        sftp = ssh.open_sftp()

        console.print(f"[cyan]→ Subiendo script de diagnóstico...[/cyan]")
        sftp.put(str(PHP_SCRIPT), remote_php)
        sftp.chmod(remote_php, 0o644)

        console.print(f"[cyan]→ Ejecutando diagnóstico...[/cyan]")
        command = (
            f"sudo -u {q(web_user)} env HOME=/tmp php {q(remote_php)} "
            f"--config={q(config_path)} --userid={userid} --courseid={courseid}"
        )
        run_log = execute_remote(
            command_logs, ssh, step="Diagnóstico", command=command,
            sudo_password=sudo_password, timeout=120, fail_on_error=False,
        )

        parsed = parse_php_output(run_log.stdout)
        if parsed:
            data = json.loads(parsed)
            output_file = SCRIPT_DIR / "diagnostico_correos_resultado.json"
            with open(output_file, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            console.print(f"\n[green]✓ Resultado guardado en {output_file}[/green]\n")
            print_results(data)
        else:
            console.print(f"[red]No se pudo parsear la salida PHP.[/red]")
            console.print(f"[dim]stdout: {run_log.stdout[:2000]}[/dim]")
            if run_log.stderr:
                console.print(f"[red]stderr: {run_log.stderr[:1000]}[/red]")

    except Exception as e:
        console.print(f"[red]Error: {e}[/red]")
        raise
    finally:
        try:
            if sftp:
                try:
                    sftp.remove(remote_php)
                except Exception:
                    pass
                sftp.close()
            if ssh:
                ssh.close()
        except Exception:
            pass


def print_results(data):
    console.print(f"[bold]Usuario:[/bold] {data.get('user_name', '?')} (id={data['userid']})")
    console.print(f"[bold]Curso:[/bold] id={data['courseid']}")
    console.print(f"[bold]Roles del usuario:[/bold] {data.get('user_roles', [])}")
    console.print(f"[bold]Staff del curso:[/bold] {data.get('staff_count', 0)} usuarios")

    # Staff names
    for uid, name in (data.get('staff_names') or {}).items():
        is_teacher = int(uid) in (data.get('teacher_ids') or [])
        tag = " [teacher]" if is_teacher else ""
        console.print(f"  - {name} (id={uid}){tag}")

    console.print(f"\n[bold yellow]═══ CACHE iTOP (block_adv_reports_values) ═══[/bold yellow]")
    for entry in data.get('cache', []):
        console.print(f"  reportid={entry['reportid']}  stat=[cyan]{entry['stat']}[/cyan]  "
                       f"value=[green]{entry['clean']}[/green]")
    if not data.get('cache'):
        console.print("  [dim](sin datos en cache)[/dim]")

    console.print(f"\n[bold yellow]═══ CONTEOS DE MENSAJES (distintas metodologías) ═══[/bold yellow]")
    counts = data.get('counts', {})
    target_value = None
    # Check if any cache entry for teacher_num_messages_with_students exists
    for entry in data.get('cache', []):
        if entry['stat'] == 'teacher_num_messages_with_students':
            target_value = entry['clean']
            break

    for key, val in counts.items():
        marker = ""
        if target_value is not None and str(val) == target_value:
            marker = " [bold green]← COINCIDE CON CACHE iTOP[/bold green]"
        console.print(f"  {key:<45} = [bold]{val}[/bold]{marker}")

    # Highlight which count = 5 (or whatever iTOP shows)
    console.print(f"\n[bold yellow]═══ MUESTRA DE MENSAJES DIRECTOS ═══[/bold yellow]")
    for msg in data.get('messages_sample', []):
        console.print(f"  [{msg['type']}] {msg['time']}  from={msg['from']} (id={msg['from_id']})")
        console.print(f"           {msg['preview'][:80]}")

    console.print()


if __name__ == "__main__":
    main()
