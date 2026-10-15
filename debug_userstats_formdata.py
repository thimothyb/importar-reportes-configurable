#!/usr/bin/env python3
"""
Debug: dump formdata de columnas userstats desde iTOP.
Sube y ejecuta debug_userstats_formdata.php en el servidor seleccionado.
"""
from __future__ import annotations
import json, sys, uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from cr_common import (
    CommandLog, connect_ssh, console, execute_remote, load_inventory,
    logger, parse_php_output, prompt_server_selection, q, run_remote_command,
    safe_text,
)

SCRIPT_DIR = Path(__file__).resolve().parent
PHP_SCRIPT = "debug_userstats_formdata.php"


def main():
    inv_path = SCRIPT_DIR / "inventario.json"
    if not inv_path.exists():
        console.print(f"[red]No se encontró {inv_path}[/red]")
        sys.exit(1)

    servers, settings = load_inventory(inv_path)

    selected = prompt_server_selection(servers)
    if not selected:
        console.print("[yellow]No se seleccionaron servidores.[/yellow]")
        return

    server = selected[0] if isinstance(selected, list) else selected

    courseid = safe_text("Course ID a inspeccionar", default="604")
    if not courseid:
        return

    ssh = connect_ssh(server)
    sftp = ssh.open_sftp()

    # Upload
    local_php = SCRIPT_DIR / PHP_SCRIPT
    token = uuid.uuid4().hex[:8]
    remote_php = f"/tmp/cr_debug_userstats_{token}.php"
    sftp.put(str(local_php), remote_php)
    sftp.chmod(remote_php, 0o644)
    console.print(f"[green]✔ Script subido: {remote_php}[/]")

    moodle_path = server["moodle_path"]
    config_path = f"{moodle_path}/config.php"
    web_user = server["web_user"]

    cmd = (
        f"sudo -u {q(web_user)} php {q(remote_php)}"
        f" --config={q(config_path)}"
        f" --courseid={q(courseid)}"
    )

    command_logs: list[CommandLog] = []
    log = execute_remote(
        command_logs, ssh,
        step="Debug userstats formdata",
        command=cmd,
        timeout=120,
        fail_on_error=False,
    )

    result = parse_php_output(log.stdout)
    if result is None:
        result = parse_php_output(log.stderr)

    if result is None:
        console.print("[red]No se pudo parsear la salida.[/]")
        console.print(f"STDOUT:\n{log.stdout[:3000]}")
        console.print(f"STDERR:\n{log.stderr[:3000]}")
    else:
        console.print(f"\n[bold]Columnas userstats encontradas: {len(result.get('columns', []))}[/]\n")
        for col in result.get("columns", []):
            console.print(f"[cyan]═══ Report {col['report_id']} | Col #{col['col_index']} | {col.get('columname','')} ═══[/]")
            console.print(f"  formdata keys: {col['formdata_keys']}")
            console.print(f"  formdata full:")
            console.print(json.dumps(col["formdata_full"], indent=2, ensure_ascii=False))
            console.print()

    # Cleanup
    try:
        run_remote_command(ssh, f"rm -f {q(remote_php)}")
    except Exception:
        pass

    ssh.close()


if __name__ == "__main__":
    main()
