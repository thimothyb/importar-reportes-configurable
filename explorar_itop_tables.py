#!/usr/bin/env python3
"""
Explorar las tablas iTOP legacy en un servidor Moodle.

Descubre los stat names y la estructura de datos en las tablas
block_adv_reports_* para un curso determinado.

Uso:
    python explorar_itop_tables.py

Se conecta al servidor seleccionado del inventario.json y ejecuta
queries SQL via CLI de Moodle (php admin/cli/cfg.php + mysql directo).
"""

from __future__ import annotations

import json
import sys
import textwrap
from pathlib import Path

# Importar utilidades compartidas del proyecto
sys.path.insert(0, str(Path(__file__).resolve().parent))
from cr_common import (
    load_inventory,
    select_server,
    connect_ssh,
    run_remote,
    console,
)

COURSE_ID = 67  # Curso a explorar (cambiar si es necesario)

# Queries SQL para explorar las tablas iTOP
QUERIES = {
    "1_tables_exist": """
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME LIKE 'mdl_block_adv_reports_%'
        ORDER BY TABLE_NAME;
    """,

    "2_usrstats_stats": """
        SELECT stat, COUNT(*) as total, MIN(value) as min_val, MAX(value) as max_val
        FROM mdl_block_adv_reports_usrstats
        WHERE courseid = {courseid}
        GROUP BY stat
        ORDER BY stat;
    """,

    "3_usrstats_sample": """
        SELECT userid, stat, value, dim1, timecreated
        FROM mdl_block_adv_reports_usrstats
        WHERE courseid = {courseid}
        LIMIT 30;
    """,

    "4_values_stats": """
        SELECT stat, reportid, COUNT(*) as total
        FROM mdl_block_adv_reports_values
        WHERE courseid = {courseid}
        GROUP BY stat, reportid
        ORDER BY reportid, stat;
    """,

    "5_values_sample": """
        SELECT userid, reportid, stat, value
        FROM mdl_block_adv_reports_values
        WHERE courseid = {courseid}
        LIMIT 30;
    """,

    "6_times_sample": """
        SELECT userid, dedicationtime, graceperiods, coursehours, passhours, timemodified
        FROM mdl_block_adv_reports_times t
        LEFT JOIN mdl_block_adv_reports_chours ch ON ch.courseid = t.course
        LEFT JOIN mdl_block_adv_reports_tmethod tm ON tm.courseid = t.course
        WHERE t.course = {courseid}
        LIMIT 20;
    """,

    "7_daily_sample": """
        SELECT userid, thedate, totalseconds, totalhits
        FROM mdl_block_adv_reports_daily
        WHERE courseid = {courseid}
        LIMIT 20;
    """,

    "8_scorm_sample": """
        SELECT userid, scoid, attempt, dedicationtime, timemodified
        FROM mdl_block_adv_reports_sco_times
        WHERE course = {courseid}
        LIMIT 20;
    """,

    "9_chours": """
        SELECT * FROM mdl_block_adv_reports_chours
        WHERE courseid = {courseid};
    """,

    "10_tmethod": """
        SELECT * FROM mdl_block_adv_reports_tmethod
        WHERE courseid = {courseid};
    """,

    "11_cert_sample": """
        SELECT userid, certid, timecreated
        FROM mdl_block_adv_reports_cert
        WHERE courseid = {courseid}
        LIMIT 10;
    """,

    "12_videoconf_sample": """
        SELECT userid, activityid, duration, timestart
        FROM mdl_block_adv_reports_videoconf
        WHERE courseid = {courseid}
        LIMIT 10;
    """,

    "13_sect_times_sample": """
        SELECT userid, sectionid, dedicationtime
        FROM mdl_block_adv_reports_sect_times
        WHERE courseid = {courseid}
        LIMIT 10;
    """,
}


def get_db_credentials(ssh_client, server):
    """Obtiene credenciales de la BD desde config.php de Moodle."""
    moodle_path = server["moodle_path"]
    web_user = server.get("web_user", "www-data")
    sudo_pw = server.get("sudo_password", "")

    cmd = f"sudo -u {web_user} php {moodle_path}/admin/cli/cfg.php --json 2>/dev/null || " \
          f"grep -E '\\$CFG->(dbhost|dbname|dbuser|dbpass)' {moodle_path}/config.php"

    rc, stdout, stderr = run_remote(ssh_client, cmd, step="get_db_creds",
                                     sudo_password=sudo_pw if sudo_pw else None)

    # Intentar parsear como JSON primero
    try:
        cfg = json.loads(stdout)
        return {
            "host": cfg.get("dbhost", "localhost"),
            "name": cfg.get("dbname"),
            "user": cfg.get("dbuser"),
            "pass": cfg.get("dbpass"),
        }
    except (json.JSONDecodeError, TypeError):
        pass

    # Fallback: parsear grep de config.php
    creds = {}
    for line in stdout.splitlines():
        line = line.strip().rstrip(";")
        if "dbhost" in line:
            creds["host"] = line.split("=")[-1].strip().strip("'\"")
        elif "dbname" in line:
            creds["name"] = line.split("=")[-1].strip().strip("'\"")
        elif "dbuser" in line:
            creds["user"] = line.split("=")[-1].strip().strip("'\"")
        elif "dbpass" in line:
            creds["pass"] = line.split("=")[-1].strip().strip("'\"")

    return creds


def run_sql(ssh_client, server, db_creds, query_name, query_sql):
    """Ejecuta una query SQL en el servidor remoto."""
    sql = query_sql.format(courseid=COURSE_ID).strip()
    sudo_pw = server.get("sudo_password", "")

    # Escapar la query para pasarla por bash
    escaped_sql = sql.replace("'", "'\\''")
    cmd = (
        f"mysql -h {db_creds['host']} -u {db_creds['user']} "
        f"-p'{db_creds['pass']}' {db_creds['name']} "
        f"-e '{escaped_sql}' 2>/dev/null"
    )

    try:
        rc, stdout, stderr = run_remote(ssh_client, cmd, step=query_name,
                                         sudo_password=sudo_pw if sudo_pw else None)
        return stdout
    except Exception as e:
        return f"ERROR: {e}"


def main():
    console.print(f"\n[bold cyan]Explorador de tablas iTOP legacy — Curso {COURSE_ID}[/bold cyan]\n")

    # Cargar inventario y seleccionar servidor
    inventory = load_inventory()
    server = select_server(inventory)
    if not server:
        console.print("[red]No se seleccionó servidor.[/red]")
        return

    console.print(f"\n[bold]Conectando a {server['name']}...[/bold]")
    ssh = connect_ssh(server)

    # Obtener credenciales de BD
    console.print("[dim]Obteniendo credenciales de BD...[/dim]")
    db_creds = get_db_credentials(ssh, server)
    if not db_creds.get("name"):
        console.print("[red]No se pudieron obtener las credenciales de la BD.[/red]")
        return
    console.print(f"[green]BD: {db_creds['name']} @ {db_creds['host']}[/green]\n")

    # Ejecutar cada query
    results = {}
    for qname, qsql in QUERIES.items():
        console.print(f"[cyan]Ejecutando: {qname}...[/cyan]")
        output = run_sql(ssh, server, db_creds, qname, qsql)
        results[qname] = output
        console.print(output if output.strip() else "[dim](sin resultados)[/dim]")
        console.print()

    # Guardar resultados
    output_file = Path(__file__).resolve().parent / "logs" / f"itop_explore_curso{COURSE_ID}.txt"
    with open(output_file, "w", encoding="utf-8") as f:
        f.write(f"Exploración iTOP legacy — Curso {COURSE_ID}\n")
        f.write("=" * 60 + "\n\n")
        for qname, output in results.items():
            f.write(f"--- {qname} ---\n")
            f.write(output + "\n\n")

    console.print(f"\n[bold green]Resultados guardados en: {output_file}[/bold green]")

    try:
        ssh.close()
    except Exception:
        pass


if __name__ == "__main__":
    main()
