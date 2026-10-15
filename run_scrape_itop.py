#!/usr/bin/env python3
"""Scrape iTOP popups to get actual checked selections."""
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
sftp.put(str(SCRIPT_DIR / "scrape_itop_selections.php"), "/tmp/scrape_itop_selections.php")

moodle_path = srv['moodle_path']
cmd = (f"sudo -u www-data php /tmp/scrape_itop_selections.php"
       f" --config={moodle_path}/config.php"
       f" --courseid=604"
       f" --user=admin-awk-thibrown"
       f" '--pass=^[sgi<)F)15J36UN'")
print("Running scrape (may take a moment)...")
_, stdout, stderr = ssh.exec_command(cmd, timeout=60)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])

m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m:
    print("No result. Output:", out[:2000])
    sys.exit(1)

data = json.loads(m.group(1))
for s in data['selections']:
    print(f"\n{'='*60}")
    print(f"  Report: {s['report']}")
    print(f"  Column: {s['header']} (stat: {s['stat']})")
    print(f"  iTOP ids stored: {s['ids_stored']}")
    print(f"  Popup HTTP: {s['popup_http_code']}, HTML: {s['popup_html_length']} bytes")
    print(f"  All checkboxes ({s['total_count']}): {s['all_checkbox_cmids']}")
    print(f"  CHECKED ({s['checked_count']}): {s['checked_cmids']}")

ssh.close()
