#!/usr/bin/env python3
"""Reset selectedcmids/actividades en userstatsadvanced para permitir re-migración."""
import json, sys, re
from pathlib import Path
SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))
from cr_common import connect_ssh, load_inventory

apply_mode = '--apply' in sys.argv

servers, _ = load_inventory(SCRIPT_DIR / "inventario.json")
srv = next(s for s in servers if 'adalid' in s.get('name','').lower())
print(f"Connecting to {srv['name']}...")
ssh = connect_ssh(srv)
sftp = ssh.open_sftp()
sftp.put(str(SCRIPT_DIR / "reset_userstats_selections.php"), "/tmp/reset_userstats_selections.php")

apply_flag = " --apply" if apply_mode else ""
cmd = f"sudo -u www-data php /tmp/reset_userstats_selections.php --config={srv['moodle_path']}/config.php --courseid=604{apply_flag}"
print(f"Running: {cmd}")
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])
m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m:
    print("No result:", out[:2000])
    sys.exit(1)
data = json.loads(m.group(1))
print(json.dumps(data, indent=2, ensure_ascii=False))
ssh.close()
