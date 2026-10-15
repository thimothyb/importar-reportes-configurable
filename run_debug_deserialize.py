#!/usr/bin/env python3
"""Diagnóstico: compara cr_unserialize vs plain unserialize para iTOP."""
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
sftp.put(str(SCRIPT_DIR / "debug_deserialize_compare.php"), "/tmp/debug_deserialize_compare.php")
cmd = f"sudo -u www-data php /tmp/debug_deserialize_compare.php --config={srv['moodle_path']}/config.php --courseid=604"
_, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode('utf-8', errors='replace')
err = stderr.read().decode('utf-8', errors='replace')
if err.strip(): print("STDERR:", err[:500])
m = re.search(r'<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>', out, re.DOTALL)
if not m:
    print("No result:", out[:2000])
    sys.exit(1)
data = json.loads(m.group(1))
print(f"\ncr_unserialize exists: {data['cr_unserialize_exists']}")
for rep in data['results']:
    print(f"\n{'='*60}")
    print(f"  Report: {rep['report_name']} (id={rep['report_id']})")
    print(f"  cr_unserialize OK: {rep['cr_unserialize_ok']} (type={rep['cr_unserialize_type']})")
    print(f"  plain unserialize OK: {rep['plain_unserialize_ok']} (type={rep['plain_unserialize_type']})")
    print(f"  raw_first_100: {rep['raw_first_100']}")

    if rep['cr_cols']:
        print(f"\n  --- cr_unserialize columns ({len(rep['cr_cols'])}) ---")
        for col in rep['cr_cols']:
            print(f"    [{col['idx']}] \"{col['header']}\" plugin={col['pluginname']} stat={col['stat']}")
            print(f"         has_ids={col['has_ids_key']} ids_empty={col['ids_empty']} ids_value={col['ids_value']}")
            print(f"         formdata_keys={col['formdata_keys']}")

    if rep['plain_cols']:
        print(f"\n  --- plain unserialize columns ({len(rep['plain_cols'])}) ---")
        for col in rep['plain_cols']:
            print(f"    [{col['idx']}] \"{col['header']}\" plugin={col['pluginname']} stat={col['stat']}")
            print(f"         has_ids={col['has_ids_key']} ids_empty={col['ids_empty']} ids_value={col['ids_value']}")
            print(f"         formdata_keys={col['formdata_keys']}")

ssh.close()
