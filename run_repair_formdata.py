#!/usr/bin/env python3
"""Repara formdata types en reportes corruptos."""
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
sftp.put(str(SCRIPT_DIR / "repair_formdata_types.php"), "/tmp/repair_formdata_types.php")

# Build command
args = "--config={}/config.php --courseid=604 --reportids=10037,10041".format(srv['moodle_path'])
if '--apply' in sys.argv:
    args += " --apply"
    print("*** MODE: APPLY ***")
else:
    print("*** MODE: DRY-RUN (use --apply to save) ***")

cmd = f"sudo -u www-data php /tmp/repair_formdata_types.php {args}"
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])

m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m:
    print("No structured result. Raw output:")
    print(out[:3000])
    sys.exit(1)
data = json.loads(m.group(1))
print(f"\nApply mode: {data.get('apply', False)}")
for r in data['results']:
    status = "APPLIED" if r.get('applied') else r.get('note', '')
    print(f"  Report {r['id']} ({r['name']}): {r['fixes']} fixes — {status}")

ssh.close()
