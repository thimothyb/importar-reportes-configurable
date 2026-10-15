#!/usr/bin/env python3
"""Mueve actividades→selectedcmids en reportes corruptos."""
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
sftp.put(str(SCRIPT_DIR / "fix_actividades_to_selectedcmids.php"), "/tmp/fix_actividades_to_selectedcmids.php")

args = f"--config={srv['moodle_path']}/config.php --reportids=10037,10041"
if '--apply' in sys.argv:
    args += " --apply"
    print("*** MODE: APPLY ***")
else:
    print("*** MODE: DRY-RUN ***")

cmd = f"sudo -u www-data php /tmp/fix_actividades_to_selectedcmids.php {args}"
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])

m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m:
    print("No result:", out[:2000])
    sys.exit(1)
data = json.loads(m.group(1))
print(f"\nApply: {data['apply']}")
for r in data['results']:
    if 'selectedcmids' in r:
        print(f"  Report {r['id']} [{r['index']}] {r['plugin']}: selectedcmids=\"{r['selectedcmids']}\" — {r['action']}")
    else:
        print(f"  Report {r['id']}: {r.get('status','?')}")

ssh.close()
