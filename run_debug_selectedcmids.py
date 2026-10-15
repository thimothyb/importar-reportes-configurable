#!/usr/bin/env python3
"""Quick non-interactive debug: check selectedcmids on adalid course 604."""
import json, sys, os, re
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))
from cr_common import connect_ssh, load_inventory

inv_path = SCRIPT_DIR / "inventario.json"
servers, settings = load_inventory(inv_path)

# Find adalid
srv = None
for s in servers:
    if 'adalid' in s.get('name','').lower():
        srv = s
        break
if not srv:
    print("Adalid not found"); sys.exit(1)

print(f"Connecting to {srv['name']}...")
ssh = connect_ssh(srv)
sftp = ssh.open_sftp()

local_php = str(SCRIPT_DIR / "debug_selectedcmids.php")
remote_php = "/tmp/debug_selectedcmids.php"
sftp.put(local_php, remote_php)

moodle_path = srv['moodle_path']
config = f"{moodle_path}/config.php"
cmd = f"sudo -u www-data php {remote_php} --config={config} --courseid=604"
print(f"Running: {cmd}")

stdin, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip():
    print("STDERR:", err[:500])

m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if m:
    data = json.loads(m.group(1))
    for col in data.get('cols', []):
        print(f"\n  Report: {col['report']}")
        print(f"  Column: {col['columname']} (stat: {col['stat']})")
        print(f"  actividades: {col['actividades']}")
        print(f"  selectedcmids: {col['selectedcmids']} (type: {col['selectedcmids_type']})")
else:
    print("No CR_RESULT found. Output:", out[:1000])

ssh.close()
