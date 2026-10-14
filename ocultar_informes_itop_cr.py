#!/usr/bin/env python3
"""
Ocultar bloques de Configurable Reports en cursos con datos iTOP.

Despliega el script PHP en un servidor vía SSH, lo ejecuta y muestra
un resumen de las acciones realizadas o que se realizarían (dry-run).

Modos:
  --dry-run  (default) Solo muestra qué bloques se ocultarían.
  --apply    Ejecuta: pone visible=0 en los bloques.
  --restore  Deshace: pone visible=1 en los bloques ocultos.
"""

import json
import os
import re
import sys
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cr_common import load_inventory, prompt_server_selection, connect_ssh, run_remote_command

INVENTORY_FILE = Path(os.path.dirname(os.path.abspath(__file__))) / "inventario.json"
PHP_SCRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ocultar_informes_itop.php")


def parse_cr_result(stdout: str):
    """Extract JSON from <<<CR_RESULT>>>...<<<END_CR_RESULT>>> markers."""
    match = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', stdout, re.DOTALL)
    if match:
        return json.loads(match.group(1))
    return None


def print_summary(data: dict):
    """Print formatted summary."""
    mode = data.get('mode', '?')
    mode_label = {
        'dry-run': '🔍 SIMULACIÓN (dry-run)',
        'apply': '✅ APLICADO',
        'restore': '🔄 RESTAURADO',
    }.get(mode, mode)

    print("\n" + "=" * 70)
    print(f"  OCULTAR INFORMES EN CURSOS CON iTOP — {mode_label}")
    print("=" * 70)
    print(f"  Servidor:              {data.get('server', '?')}")
    print(f"  Fecha:                 {data.get('timestamp', '?')}")
    print(f"  Cursos con iTOP:       {data.get('total_itop_courses', '?')}")
    print(f"  Bloques encontrados:   {data.get('total_blocks_found', '?')}")
    print(f"  Reportes en cursos:    {data.get('total_reports_in_itop_courses', '?')}")
    print()

    if 'error' in data:
        print(f"  ⚠ {data['error']}")
        print()
        return

    already = data.get('already_in_target_state', 0)

    if mode == 'dry-run':
        to_hide = data.get('blocks_to_hide', 0)
        courses_would = data.get('courses_would_affect', 0)
        print(f"  Ya ocultos:            {already}")
        print(f"  Bloques a ocultar:     {to_hide}")
        print(f"  Cursos a afectar:      {courses_would}")
        print()

        preview = data.get('preview', [])
        if preview:
            print("  PREVIEW — bloques que se ocultarían:")
            print("  " + "-" * 60)
            current_course = None
            for item in preview:
                cid = item.get('courseid')
                if cid != current_course:
                    current_course = cid
                    sn = item.get('shortname', '?')
                    print(f"\n  Curso {cid} ({sn}):")
                print(f"    • block_instance #{item.get('block_instance_id')}"
                      f"  visible={item.get('current_visible')} → {item.get('would_set')}")
            print()

        if to_hide > 0:
            print("  Para aplicar los cambios, ejecuta con --apply")
        else:
            print("  ✓ Todos los bloques ya están ocultos.")
        print()

    elif mode in ('apply', 'restore'):
        changed = data.get('blocks_changed', 0)
        courses = data.get('courses_affected', 0)
        action = 'restaurados' if mode == 'restore' else 'ocultados'
        print(f"  Bloques {action}:       {changed}")
        print(f"  Cursos afectados:      {courses}")
        print(f"  Caches purgados:       {'Sí' if data.get('caches_purged') else 'No'}")
        print()

        details = data.get('details', [])
        if details:
            print(f"  DETALLE — bloques {action}:")
            print("  " + "-" * 60)
            current_course = None
            for item in details:
                cid = item.get('courseid')
                if cid != current_course:
                    current_course = cid
                    sn = item.get('shortname', '?')
                    print(f"\n  Curso {cid} ({sn}):")
                print(f"    • block_instance #{item.get('block_instance_id')}"
                      f"  visible: {item.get('old_visible')} → {item.get('new_visible')}")
            print()

    # Course list
    course_list = data.get('course_list', {})
    if course_list:
        print(f"  Cursos involucrados ({len(course_list)}):")
        for cid, name in sorted(course_list.items(), key=lambda x: int(x[0])):
            print(f"    {cid}: {name}")
        print()

    print("=" * 70)


def main():
    print("=" * 70)
    print("  Ocultar Informes de Configurable Reports en cursos con iTOP")
    print("=" * 70)
    print()

    # Parse mode.
    mode = '--dry-run'
    for arg in sys.argv[1:]:
        if arg in ('--apply', '--restore', '--dry-run'):
            mode = arg

    mode_clean = mode.lstrip('-')
    print(f"  Modo: {mode_clean}")
    print()

    if mode_clean == 'apply':
        confirm = input("  ⚠ Esto OCULTARÁ los bloques en el servidor. ¿Continuar? (s/N): ").strip().lower()
        if confirm not in ('s', 'si', 'sí', 'y', 'yes'):
            print("  Cancelado.")
            return
        print()

    # Load inventory.
    servers_list, settings = load_inventory(INVENTORY_FILE)
    servers = prompt_server_selection(servers_list)
    if not servers:
        print("No se seleccionó ningún servidor.")
        return

    server = servers[0]
    moodle_path = server["moodle_path"]
    web_user = server.get("web_user", "www-data")
    sudo_password = server.get("sudo_password")

    print(f"\n→ Conectando a {server['name']} ({server['host']})...")

    try:
        ssh = connect_ssh(server)
    except Exception as e:
        print(f"Error conectando SSH: {e}")
        return

    try:
        # Upload PHP script via SFTP.
        remote_php = f"{moodle_path}/ocultar_informes_itop.php"
        print("→ Subiendo script PHP...")
        sftp = ssh.open_sftp()
        sftp.put(PHP_SCRIPT, remote_php)
        sftp.close()

        # Execute.
        print(f"→ Ejecutando ({mode_clean})...\n")

        cmd = (
            f"cd {moodle_path} && "
            f"sudo -u {web_user} php {remote_php} {mode}"
        )

        exit_code, stdout, stderr = run_remote_command(
            ssh, cmd, sudo_password=sudo_password, timeout=120
        )

        if stderr:
            for line in stderr.strip().split('\n'):
                if line.strip():
                    print(f"  {line.strip()}")

        # Parse result.
        data = parse_cr_result(stdout)
        if data is None:
            print("\n⚠ No se pudo parsear la salida PHP.")
            print("STDOUT:", stdout[:2000] if stdout else "(vacío)")
            return

        if 'error' in data and data['error']:
            print(f"\n⚠ {data['error']}")

        # Show summary.
        print_summary(data)

        # Save results.
        outdir = os.path.dirname(os.path.abspath(__file__))
        server_slug = server['name'].replace(' ', '_').replace('(', '').replace(')', '')
        outfile = os.path.join(outdir, f"ocultar_informes_{mode_clean}_{server_slug}.json")
        with open(outfile, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        print(f"  Resultados guardados en: {outfile}")

        # Cleanup remote.
        run_remote_command(ssh, f"rm -f {remote_php}", timeout=10)
        print("  Temporales limpiados.\n")

    finally:
        ssh.close()


if __name__ == "__main__":
    main()
