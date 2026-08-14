#!/usr/bin/env python3
"""
Utilidades compartidas por los scripts de migración "Configurable Reports"
(por SSH):

  - auditar_cr.py                      → detecta cursos con el bloque alquilado activo.
  - desplegar_plugin_cr.py             → instala el plugin propio (código nuevo).
  - instalar_plantillas_cr.py          → agrega el bloque propio y sus plantillas por curso.
  - desinstalar_plugin_alquilado_cr.py → desinstala el plugin alquilado (a nivel de plataforma).

Centraliza: carga de inventario.json, conexión SSH/SFTP (paramiko), ejecución
remota con soporte de sudo por contraseña, empaquetado/descarga de backups, y
parseo de la salida JSON que emiten los CLIs PHP entre los marcadores
<<<CR_RESULT>>> ... <<<END_CR_RESULT>>>.
"""

from __future__ import annotations

import json
import logging
import posixpath
import re
import shlex
import uuid
from dataclasses import dataclass
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence, Tuple

import paramiko
import questionary
from questionary import Choice
from rich.console import Console

console = Console()

REMOTE_TMP_DIR = "/tmp"

# --- Logging estructurado -----------------------------------------------------
_LOG_DIR = Path(__file__).resolve().parent / "logs"
_LOG_DIR.mkdir(exist_ok=True)

logger = logging.getLogger("cr_migration")
logger.setLevel(logging.DEBUG)

_file_handler = RotatingFileHandler(
    _LOG_DIR / "cr_migration.log",
    maxBytes=5 * 1024 * 1024,  # 5 MB
    backupCount=3,
    encoding="utf-8",
)
_file_handler.setFormatter(
    logging.Formatter("%(asctime)s | %(levelname)-8s | %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
)
logger.addHandler(_file_handler)

# Marcadores con los que los CLI PHP delimitan su salida JSON.
RESULT_RE = re.compile(r"<<<CR_RESULT>>>(.*?)<<<END_CR_RESULT>>>", re.DOTALL)


# --- Modelos ------------------------------------------------------------
@dataclass
class CommandLog:
    """Resultado detallado de un comando remoto."""

    step: str
    command: str
    exit_status: int
    stdout: str
    stderr: str


class RemoteCommandError(RuntimeError):
    """Error lanzado cuando un comando remoto devuelve exit status != 0."""

    def __init__(self, log: CommandLog):
        self.log = log
        detail = log.stderr.rstrip("\n") or log.stdout.rstrip("\n") or f"exit status {log.exit_status}"
        super().__init__(f"{log.step}: {detail}")


def q(value: str) -> str:
    """Escapa valores para shell remoto (Linux)."""
    return shlex.quote(value)


# --- Inventario -----------------------------------------------------------
def load_inventory(path: Path) -> Tuple[List[Dict[str, Any]], Dict[str, Any]]:
    """
    Carga inventario.json. Acepta:
      - un array de servidores, o
      - un objeto {"settings": {...}, "servers": [...]}.

    Devuelve (servers, settings).
    """
    if not path.exists():
        raise FileNotFoundError(f"No se encontró el archivo de inventario: {path}")

    raw = json.loads(path.read_text(encoding="utf-8"))
    settings: Dict[str, Any] = {}
    if isinstance(raw, dict) and "servers" in raw:
        servers = raw["servers"]
        settings = raw.get("settings", {}) or {}
    elif isinstance(raw, list):
        servers = raw
    else:
        raise ValueError(
            "inventario.json debe ser un array de servidores o un objeto con clave 'servers'."
        )

    if not isinstance(servers, list) or not servers:
        raise ValueError("El inventario está vacío o su formato es inválido.")

    required_fields = ("name", "host", "ssh_user", "moodle_path", "web_user")
    for idx, server in enumerate(servers, start=1):
        if not isinstance(server, dict):
            raise ValueError(f"Entrada inválida en servidor #{idx}: debe ser un objeto JSON.")
        missing = [f for f in required_fields if not server.get(f)]
        if missing:
            raise ValueError(f"Servidor #{idx} incompleto. Faltan campos: {', '.join(missing)}")
        server.setdefault("port", 22)
        server.setdefault("web_group", server["web_user"])
        server.setdefault("sudo_requires_password", False)

    return servers, settings


def normalize_courseids(value: Any) -> List[str]:
    """Normaliza la lista de cursos (ids o shortnames) a lista de strings sin vacíos."""
    if value is None:
        return []
    if isinstance(value, (int, str)):
        tokens = str(value).split(",")
    elif isinstance(value, (list, tuple)):
        tokens = [str(t) for t in value]
    else:
        return []
    return [t.strip() for t in tokens if str(t).strip()]


# --- SSH --------------------------------------------------------------------
def connect_ssh(server: Dict[str, Any]) -> paramiko.SSHClient:
    """Crea conexión SSH usando contraseña y/o llave según inventario."""
    client = paramiko.SSHClient()

    # Cargar known_hosts del sistema si existe; si no, usar WarningPolicy
    # para registrar fingerprints desconocidos en vez de aceptarlos en silencio.
    known_hosts = Path.home() / ".ssh" / "known_hosts"
    if known_hosts.is_file():
        client.load_host_keys(str(known_hosts))
    client.set_missing_host_key_policy(paramiko.WarningPolicy())

    connect_kwargs: Dict[str, Any] = {
        "hostname": server["host"],
        "port": int(server.get("port", 22)),
        "username": server["ssh_user"],
        "timeout": 20,
        "banner_timeout": 20,
        "auth_timeout": 20,
    }
    if server.get("ssh_key_path"):
        connect_kwargs["key_filename"] = server["ssh_key_path"]
    if server.get("ssh_key_passphrase"):
        connect_kwargs["passphrase"] = server["ssh_key_passphrase"]
    if server.get("ssh_password"):
        connect_kwargs["password"] = server["ssh_password"]

    logger.info("SSH conectando a %s@%s:%s", connect_kwargs["username"], connect_kwargs["hostname"], connect_kwargs["port"])
    client.connect(**connect_kwargs)
    logger.info("SSH conectado a %s@%s:%s", connect_kwargs["username"], connect_kwargs["hostname"], connect_kwargs["port"])
    return client


def run_remote_command(
    ssh: paramiko.SSHClient,
    command: str,
    *,
    sudo_password: Optional[str] = None,
    timeout: int = 1800,
) -> Tuple[int, str, str]:
    """
    Ejecuta un comando remoto y devuelve (exit_status, stdout, stderr).
    Si hay sudo password configurado y el comando inicia con 'sudo ', la envía por stdin.
    """
    prepared_command = command
    use_sudo_password = bool(sudo_password) and command.lstrip().startswith("sudo ")
    if use_sudo_password:
        prepared_command = command.replace("sudo ", "sudo -S -p '' ", 1)

    logger.debug("Ejecutando: %s", command)
    stdin, stdout, stderr = ssh.exec_command(prepared_command, get_pty=use_sudo_password, timeout=timeout)
    if use_sudo_password:
        stdin.write(f"{sudo_password}\n")
        stdin.flush()
        stdin.channel.shutdown_write()  # Cerrar stdin para no dejar la password accesible

    stdout_text = stdout.read().decode("utf-8", errors="replace")
    stderr_text = stderr.read().decode("utf-8", errors="replace")
    exit_status = stdout.channel.recv_exit_status()

    # Limpiar eco de password del PTY en stdout (sudo -S con get_pty puede
    # dejar la contraseña como primera línea del output).
    if use_sudo_password and stdout_text.startswith("\r\n"):
        stdout_text = stdout_text.lstrip("\r\n")

    logger.debug("exit_status=%d stdout_len=%d stderr_len=%d", exit_status, len(stdout_text), len(stderr_text))
    return exit_status, stdout_text, stderr_text


def execute_remote(
    command_logs: List[CommandLog],
    ssh: paramiko.SSHClient,
    *,
    step: str,
    command: str,
    sudo_password: Optional[str] = None,
    timeout: int = 1800,
    fail_on_error: bool = True,
) -> CommandLog:
    """Ejecuta comando remoto, guarda su salida y opcionalmente falla si exit_status != 0."""
    exit_status, stdout_text, stderr_text = run_remote_command(
        ssh, command, sudo_password=sudo_password, timeout=timeout
    )
    log = CommandLog(step=step, command=command, exit_status=exit_status, stdout=stdout_text, stderr=stderr_text)
    command_logs.append(log)
    if fail_on_error and exit_status != 0:
        logger.error("Fallo en '%s': exit=%d stderr=%s", step, exit_status, stderr_text.strip()[:300])
        raise RemoteCommandError(log)
    logger.info("OK '%s' exit=%d", step, exit_status)
    return log


def safe_cleanup(
    ssh: paramiko.SSHClient,
    command: str,
    cleanup_errors: List[str],
    *,
    sudo_password: Optional[str] = None,
) -> None:
    """Ejecuta limpieza sin romper el finally, registrando errores."""
    try:
        exit_status, stdout_text, stderr_text = run_remote_command(ssh, command, sudo_password=sudo_password)
        if exit_status != 0:
            cleanup_errors.append(stderr_text.strip() or stdout_text.strip() or f"exit {exit_status}")
    except Exception as exc:  # noqa: BLE001
        cleanup_errors.append(str(exc))


# --- Backups remotos --------------------------------------------------------
def backup_remote_dir(  # noqa: PLR0913
    command_logs: List[CommandLog],
    ssh: paramiko.SSHClient,
    sftp: "paramiko.SFTPClient",
    *,
    remote_parent_dir: str,
    dir_name: str,
    local_backup_path: Path,
    sudo_password: Optional[str] = None,
) -> List[str]:
    """
    Empaqueta {remote_parent_dir}/{dir_name} en un .tar.gz temporal remoto,
    lo descarga a local_backup_path y borra el temporal remoto (best-effort).

    Lanza RemoteCommandError si el tar falla (p.ej. la carpeta no existe).
    Devuelve la lista de errores de limpieza (normalmente vacía).
    """
    token = uuid.uuid4().hex
    remote_tar = posixpath.join(REMOTE_TMP_DIR, f"cr_backup_{token}.tar.gz")

    execute_remote(
        command_logs, ssh, step="Backup - empaquetar",
        command=f"sudo tar -czf {q(remote_tar)} -C {q(remote_parent_dir)} {q(dir_name)}",
        sudo_password=sudo_password,
    )
    execute_remote(
        command_logs, ssh, step="Backup - permisos de lectura",
        command=f"sudo chmod 644 {q(remote_tar)}",
        sudo_password=sudo_password,
    )

    local_backup_path.parent.mkdir(parents=True, exist_ok=True)
    sftp.get(remote_tar, str(local_backup_path))

    cleanup_errors: List[str] = []
    safe_cleanup(ssh, f"sudo rm -f {q(remote_tar)}", cleanup_errors, sudo_password=sudo_password)
    return cleanup_errors


# --- Parseo del JSON del CLI PHP -------------------------------------------
def parse_php_output(stdout_text: str) -> Optional[Dict[str, Any]]:
    """Extrae el JSON delimitado por los marcadores del CLI PHP."""
    match = RESULT_RE.search(stdout_text)
    if not match:
        return None
    try:
        return json.loads(match.group(1))
    except json.JSONDecodeError:
        return None


# --- Interacción compartida -------------------------------------------------
def prompt_server_selection(servers: Sequence[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Checkbox para elegir 1, varios o todos los servidores."""
    choices = [Choice("✅ Todos los servidores", "__all__")]
    for index, server in enumerate(servers):
        label = f"{server['name']} ({server['host']}:{server.get('port', 22)})"
        choices.append(Choice(label, str(index)))

    answer = questionary.checkbox(
        "Selecciona las plataformas destino:",
        choices=choices,
        validate=lambda values: True if values else "Debes seleccionar al menos una plataforma.",
    ).ask()
    if answer is None:
        raise KeyboardInterrupt
    if "__all__" in answer:
        return list(servers)
    selected = {int(item) for item in answer}
    return [s for i, s in enumerate(servers) if i in selected]
