#!/usr/bin/env python3
"""Diagnóstico de matching de columnas iTOP vs nuestras."""
import json, sys, re
from pathlib import Path
SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))
from cr_common import connect_ssh, load_inventory

servers, _ = load_inventory(SCRIPT_DIR / "inventario.json")
srv = next(s for s in servers if 'adalid' in s.get('name','').lower())
print(f"Connecting to {srv['name']}...")
ssh = connect_ssh(srv)
sftp = ssh.open_sftp()
sftp.put(str(SCRIPT_DIR / "debug_column_matching.php"), "/tmp/debug_column_matching.php")
cmd = f"sudo -u www-data php /tmp/debug_column_matching.php --config={srv['moodle_path']}/config.php --courseid=604"
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])
m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m:
    print("No result:", out[:2000])
    sys.exit(1)
data = json.loads(m.group(1))

# Pretty print analysis
for side in ['itop_reports', 'own_reports']:
    label = "iTOP" if 'itop' in side else "NUESTRO"
    print(f"\n{'='*60}")
    print(f"  {label} REPORTS")
    print(f"{'='*60}")
    for rep in data['data'][side]:
        print(f"\n  [{rep['id']}] {rep['name']} — {len(rep['columns'])} columnas")
        for col in rep['columns']:
            ids_info = f" *** IDS: {col['ids_raw'][:40]}" if col.get('has_ids') else ""
            stat_info = f" stat={col['stat']}" if col.get('stat') else ""
            print(f"    [{col['idx']}] \"{col['header']}\" plugin={col['pluginname']}{stat_info}{ids_info}")

ssh.close()
