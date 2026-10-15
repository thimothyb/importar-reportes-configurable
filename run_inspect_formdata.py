#!/usr/bin/env python3
"""Inspecciona formdata de columnas userstats en un reporte."""
import json, sys, re
from pathlib import Path
SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))
from cr_common import connect_ssh, load_inventory

servers, _ = load_inventory(SCRIPT_DIR / "inventario.json")
srv = next(s for s in servers if 'adalid' in s.get('name','').lower())
ssh = connect_ssh(srv)
sftp = ssh.open_sftp()
sftp.put(str(SCRIPT_DIR / "inspect_formdata.php"), "/tmp/inspect_formdata.php")

for rid in [10037, 10041]:
    cmd = f"sudo -u www-data php /tmp/inspect_formdata.php --config={srv['moodle_path']}/config.php --reportid={rid}"
    _, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode('utf-8', errors='replace')
    err = stderr.read().decode('utf-8', errors='replace')
    m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
    if not m:
        print(f"Report {rid}: no result. {out[:500]}")
        continue
    data = json.loads(m.group(1))
    print(f"\n{'='*60}")
    print(f"Report {rid}")
    for col in data['results']:
        print(f"\n  [{col['index']}] {col['plugin']} — {col.get('header','')}")
        for k, v in col['formdata_keys'].items():
            print(f"    {k}: ({v['type']}) {v['value'][:120]}")

ssh.close()
