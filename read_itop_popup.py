#!/usr/bin/env python3
"""Read iTOP's select_grade_items.php to understand selection logic."""
import sys
from pathlib import Path
SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))
from cr_common import connect_ssh, load_inventory

servers, _ = load_inventory(SCRIPT_DIR / "inventario.json")
srv = next(s for s in servers if 'adalid' in s.get('name','').lower())
print(f"Connecting to {srv['name']}...")
ssh = connect_ssh(srv)

# Read iTOP's popup file
itop_popup = f"{srv['moodle_path']}/blocks/advanced_reports/components/columns/userstats/select_grade_items.php"
cmd = f"cat {itop_popup}"
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:200])

# Save locally
outfile = SCRIPT_DIR / "itop_select_grade_items_READONLY.php"
with open(outfile, 'w') as f:
    f.write(out)
print(f"Saved to {outfile} ({len(out)} bytes)")
print(f"\nFirst 200 lines:")
for i, line in enumerate(out.split('\n')[:200], 1):
    print(f"  {i:4d}: {line}")

ssh.close()
