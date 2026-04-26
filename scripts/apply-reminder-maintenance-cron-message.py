#!/usr/bin/env python3
"""Patch the existing hourly maintenance job message/timeout on the gateway (run as openclaw)."""
from __future__ import annotations

import pathlib
import subprocess
import sys

JOB_ID = "0c2c5220-76df-4555-a2aa-36059a9501b6"
ROOT = pathlib.Path(__file__).resolve().parent
MSG_PATH = ROOT / "reminder-maintenance-cron-message.txt"


def main() -> None:
    msg = MSG_PATH.read_text(encoding="utf-8")
    subprocess.check_call(
        [
            "openclaw",
            "cron",
            "edit",
            JOB_ID,
            "--message",
            msg,
            "--timeout-seconds",
            "300",
        ]
    )
    print("Updated cron", JOB_ID, "message + timeout 300s", file=sys.stderr)


if __name__ == "__main__":
    main()
