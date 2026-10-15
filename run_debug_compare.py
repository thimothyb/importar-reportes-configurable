#!/usr/bin/env python3
"""Compare iTOP vs our selections side by side."""
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
sftp.put(str(SCRIPT_DIR / "debug_compare_selections.php"), "/tmp/debug_compare_selections.php")

cmd = f"sudo -u www-data php /tmp/debug_compare_selections.php --config={srv['moodle_path']}/config.php --courseid=604"
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])

m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m:
    print("No result. Output:", out[:1000]); sys.exit(1)

data = json.loads(m.group(1))
for c in data['comparisons']:
    print(f"\n{'='*60}")
    print(f"  Report: {c['report']}")
    print(f"  Column: {c['column']} (stat: {c['stat']})")
    print(f"  iTOP ids ({c['itop_ids_count']}): {c['itop_ids']}")
    print(f"  Our actividades ({c['our_actividades_count']}): {c['our_actividades']}")
    print(f"  Our selectedcmids: {c['our_selectedcmids']}")
    if c['itop_ids'] is not None and c['our_actividades'] is not None:
        diff = len(c['our_actividades']) - len(c['itop_ids']) if isinstance(c['our_actividades'], list) else '?'
        print(f"  ** DIFF: our has {diff} more than iTOP **" if diff != 0 else "  OK: same count")

ssh.close()
