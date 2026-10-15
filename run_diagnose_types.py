#!/usr/bin/env python3
"""Diagnóstico: muestra los tipos de datos en cada nivel de components."""
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
sftp.put(str(SCRIPT_DIR / "diagnose_types.php"), "/tmp/diagnose_types.php")
cmd = f"sudo -u www-data php /tmp/diagnose_types.php --config={srv['moodle_path']}/config.php --courseid=604"
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])
m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m:
    print("No structured result found. Raw output:")
    print(out[:3000])
    sys.exit(1)
data = json.loads(m.group(1))
if not data.get('ok'):
    print("Error:", json.dumps(data, indent=2))
    sys.exit(1)

for rep in data['results']:
    print(f"\n{'='*60}")
    print(f"  Report {rep['id']}: {rep['name']}")
    print(f"  top={rep['top']}  columns={rep['columns']}  elements={rep['elements']}")
    if 'cols' in rep:
        for c in rep['cols']:
            marker = " <<<" if c['fd_type'] != 'object' and c['plugin'] else ""
            print(f"    [{c['i']}] plugin={c['plugin']}  el_type={c['el_type']}  fd_type={c['fd_type']}{marker}")

ssh.close()
