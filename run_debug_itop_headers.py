#!/usr/bin/env python3
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
sftp.put(str(SCRIPT_DIR / "debug_itop_headers.php"), "/tmp/debug_itop_headers.php")
cmd = f"sudo -u www-data php /tmp/debug_itop_headers.php --config={srv['moodle_path']}/config.php --courseid=604"
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])
m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m: print("No result:", out[:1000]); sys.exit(1)
data = json.loads(m.group(1))
for c in data['columns']:
    has_ids = "** HAS IDS **" if c['ids_count'] > 0 else ""
    print(f"  [{c['report']}] '{c['header']}' stat={c['stat']} ids({c['ids_count']})={c['ids']} {has_ids}")
ssh.close()
