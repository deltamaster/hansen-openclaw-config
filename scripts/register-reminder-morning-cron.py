#!/usr/bin/env python3
"""
Register the morning reminder digest on the OpenClaw gateway (run as `openclaw` on the host).
Schedule: 07:30 Asia/Shanghai every day. Telegram target matches daily news.
"""
from __future__ import annotations

import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent
MSG_PATH = ROOT / "reminder-morning-cron-message.txt"
TELEGRAM_TO = "7046769291"


def main() -> None:
    msg = MSG_PATH.read_text(encoding="utf-8")
    cmd = [
        "openclaw",
        "cron",
        "add",
        "--name",
        "Reminder morning digest (Telegram, Shanghai 7:30)",
        "--cron",
        "30 7 * * *",
        "--tz",
        "Asia/Shanghai",
        "--session",
        "isolated",
        "--channel",
        "telegram",
        "--to",
        TELEGRAM_TO,
        "--announce",
        "--timeout-seconds",
        "300",
        "--message",
        msg,
        "--model",
        "minimax/MiniMax-M2.7",
        "--light-context",
    ]
    print("Registering cron; message length:", len(msg), "bytes", file=sys.stderr)
    subprocess.check_call(cmd)


if __name__ == "__main__":
    main()
