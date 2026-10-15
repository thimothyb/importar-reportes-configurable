#!/usr/bin/env python3
"""Run diagnose_form_rendering.php on adalid via SSH."""
import sys, json
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from cr_common import connect_ssh, load_inventory

servers, cfg = load_inventory(Path(__file__).resolve().parent / "inventario.json")
server = next(s for s in servers if "adalid" in s["name"].lower())

ssh = connect_ssh(server)
sftp = ssh.open_sftp()
sftp.put(str(Path(__file__).resolve().parent / "diagnose_form_rendering.php"), "/tmp/diagnose_form_rendering.php")
sftp.close()

config = server["moodle_path"] + "/config.php"
cmd = f'sudo -u {server.get("web_user","www-data")} php /tmp/diagnose_form_rendering.php --config={config} --courses=604'
stdin, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode("utf-8", errors="replace")
err = stderr.read().decode("utf-8", errors="replace")
ssh.close()

if err.strip():
    print("STDERR:", err[:2000])

marker = "<<<CR_RESULT>>>"
end_marker = "<<<END_CR_RESULT>>>"
if marker in out:
    start = out.index(marker) + len(marker)
    end = out.index(end_marker)
    data = json.loads(out[start:end])
    print(json.dumps(data, indent=2, ensure_ascii=False))
else:
    print("No result marker found. Raw output:")
    print(out[:3000])
