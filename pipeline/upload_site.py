#!/usr/bin/env python3
"""
Upload generated web assets to a remote server via rsync over SSH.

Fill in the HOST/USER/REMOTE_ROOT/SSH_KEY placeholders with real values
before running. No secrets are committed to the repository.
"""

from __future__ import annotations

import subprocess
from pathlib import Path

HOST = "example.com"  # TODO: set your web host
USER = "username"  # TODO: SSH username
REMOTE_ROOT = "/var/www/example.com/prices"  # TODO: destination directory
SSH_KEY = "~/.ssh/id_rsa"  # TODO: path to private key or leave default

ROOT = Path(__file__).resolve().parents[1]
SITE_ROOT = ROOT / "site"


def main() -> None:
    if not SITE_ROOT.exists():
        raise SystemExit(f"Site bundle not found: {SITE_ROOT}")

    dest = f"{USER}@{HOST}:{REMOTE_ROOT.rstrip('/')}/"
    ssh_key = str(Path(SSH_KEY).expanduser())

    cmd = [
        "rsync",
        "-avz",
        "--delete",
        "-e",
        f"ssh -i {ssh_key}",
        f"{SITE_ROOT}/",
        dest,
    ]

    print("Uploading site with command:")
    print(" ".join(cmd))
    subprocess.check_call(cmd)


if __name__ == "__main__":
    main()
