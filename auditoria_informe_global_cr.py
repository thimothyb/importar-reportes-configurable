#!/usr/bin/env python3
"""
Auditoría de coherencia del Informe Global.

Despliega el script PHP de auditoría en un servidor via SSH,
ejecuta la comparación bridge/cache vs cálculo propio para ~50 cursos,
y muestra un resumen de discrepancias.
"""

import json
import os
import re
import sys
import textwrap
from pathlib import Path

# Add parent dir for cr_common
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cr_common import load_inventory, prompt_server_selection, connect_ssh, run_remote_command

INVENTORY_FILE = Path(os.path.dirname(os.path.abspath(__file__))) / "inventario.json"
PHP_SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "auditoria_informe_global.php")

def parse_cr_result(stdout: str):
    """Extract JSON from <<<CR_RESULT>>>...<<<END_CR_RESULT>>> markers."""
    match = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', stdout, re.DOTALL)
    if match:
        return json.loads(match.group(1))
    return None

def print_summary(data: dict):
    """Print formatted summary of audit results."""
    print("\n" + "=" * 80)
    print("  AUDITORÍA DE COHERENCIA — INFORME GLOBAL")
    print("=" * 80)
    print(f"  Servidor: {data.get('server', '?')}")
    print(f"  Fecha: {data.get('timestamp', '?')}")
    print(f"  Cursos con cache iTOP: {data.get('total_courses_with_cache', '?')}")
    print(f"  Cursos auditados: {data.get('courses_audited', '?')}")
    print(f"  Usuarios auditados: {data.get('total_users_audited', '?')}")
    print(f"  Total discrepancias: {data.get('total_discrepancies', 0)}")
    print()

    # Summary by stat
    summary = data.get('summary_by_stat', {})
    if summary:
        print("  RESUMEN POR ESTADÍSTICA:")
        print("  " + "-" * 70)
        print(f"  {'Estadística':<25} {'Comparados':>10} {'Discrepancias':>14} {'Coincidencia':>12}")
        print("  " + "-" * 70)
        for stat, info in sorted(summary.items()):
            tc = info.get('total_compared', 0)
            disc = info.get('discrepancies', 0)
            rate = info.get('match_rate', 'N/A')
            marker = " ⚠" if disc > 0 else " ✓"
            print(f"  {stat:<25} {tc:>10} {disc:>14} {rate:>12}{marker}")
        print("  " + "-" * 70)
        print()

    # Courses with discrepancies
    courses = data.get('courses', [])
    courses_with_disc = [c for c in courses if any(u.get('discrepancies') for u in c.get('users', []))]

    if courses_with_disc:
        print(f"  CURSOS CON DISCREPANCIAS ({len(courses_with_disc)}):")
        print("  " + "-" * 70)
        for course in courses_with_disc:
            cid = course.get('courseid')
            cname = course.get('shortname', course.get('fullname', '?'))
            print(f"\n  Curso {cid}: {cname}")
            for user in course.get('users', []):
                discs = user.get('discrepancies', [])
                if discs:
                    uid = user.get('userid')
                    uname = user.get('fullname', user.get('username', '?'))
                    print(f"    Usuario {uid} ({uname}):")
                    for d in discs:
                        print(f"      • {d}")

                    # Show tiempo_total detail if discrepant
                    comps = user.get('comparisons', {})
                    tt = comps.get('tiempo_total', {})
                    if tt.get('bridge') and any('tiempo_total' in d for d in discs):
                        print(f"        → Bridge: {tt.get('bridge')}")
                        print(f"        → Propio: {tt.get('own_calc_formatted')} ({tt.get('own_calc_seconds')}s)")
                        print(f"        → Sum diario: {tt.get('sum_daily_formatted')} ({tt.get('sum_daily_seconds')}s)")
                        print(f"        → Días con datos: {tt.get('daily_days_count')}")
        print()
    else:
        print("  ✓ No se encontraron discrepancias en los cursos auditados.")
        print()

    print("=" * 80)


def main():
    print("=" * 60)
    print("  Auditoría de coherencia — Informe Global")
    print("=" * 60)
    print()

    # Load inventory
    servers_list, settings = load_inventory(INVENTORY_FILE)
    servers = prompt_server_selection(servers_list)
    if not servers:
        print("No se seleccionó ningún servidor.")
        return

    server = servers[0]

    # Ask parameters
    try:
        max_courses = int(input("Máximo de cursos a auditar [50]: ").strip() or "50")
    except ValueError:
        max_courses = 50
    try:
        sample_users = int(input("Usuarios muestra por curso [5]: ").strip() or "5")
    except ValueError:
        sample_users = 5

    moodle_path = server["moodle_path"]
    web_user = server.get("web_user", "www-data")
    sudo_password = server.get("sudo_password")

    print(f"\n→ Conectando a {server['name']} ({server['host']})...")

    # Connect SSH
    try:
        ssh = connect_ssh(server)
    except Exception as e:
        print(f"Error conectando SSH: {e}")
        return

    try:
        # Upload PHP script via SFTP
        remote_php = "/tmp/auditoria_informe_global.php"
        print("→ Subiendo script PHP...")
        sftp = ssh.open_sftp()
        sftp.put(PHP_SCRIPT, remote_php)
        sftp.close()

        # Execute
        print(f"→ Ejecutando auditoría ({max_courses} cursos, {sample_users} usuarios/curso)...")
        print("  (esto puede tardar varios minutos)\n")

        cmd = (
            f"cd {moodle_path} && "
            f"sudo -u {web_user} php {remote_php} {max_courses} {sample_users}"
        )

        exit_code, stdout, stderr = run_remote_command(
            ssh, cmd, sudo_password=sudo_password, timeout=600
        )

        if stderr:
            # Show progress lines
            for line in stderr.strip().split('\n'):
                if line.strip():
                    print(f"  {line.strip()}")

        # Parse result
        data = parse_cr_result(stdout)
        if data is None:
            print("\n⚠ No se pudo parsear la salida PHP.")
            print("STDOUT:", stdout[:2000] if stdout else "(vacío)")
            return

        if 'error' in data:
            print(f"\n⚠ Error: {data['error']}")
            return

        # Show summary
        print_summary(data)

        # Save full results
        outdir = os.path.dirname(os.path.abspath(__file__))
        outfile = os.path.join(outdir, f"auditoria_{server['name'].replace(' ', '_').replace('(', '').replace(')', '')}.json")
        with open(outfile, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        print(f"  Resultados completos guardados en: {outfile}")

        # Cleanup remote temp
        run_remote_command(ssh, f"rm -f {remote_php}", timeout=10)
        print("  Temporales limpiados.\n")

    finally:
        ssh.close()


if __name__ == "__main__":
    main()
