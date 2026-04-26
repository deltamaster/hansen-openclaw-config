#!/usr/bin/env python3
"""
Register hourly reminder DB maintenance on the OpenClaw gateway (as `openclaw`).
Expiring rows + stale one-shot crons + dedupe future duplicates. No Telegram delivery.
Schedule: every hour at :00 Asia/Shanghai.
"""
from __future__ import annotations

import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent
MSG_PATH = ROOT / "reminder-maintenance-cron-message.txt"


def main() -> None:
    msg = MSG_PATH.read_text(encoding="utf-8")
    cmd = [
        "openclaw",
        "cron",
        "add",
        "--name",
        "Reminder DB maintenance (hourly, Shanghai)",
        "--cron",
        "0 * * * *",
        "--tz",
        "Asia/Shanghai",
        "--session",
        "isolated",
        "--no-deliver",
        "--timeout-seconds",
        "300",
        "--message",
        msg,
        "--model",
        "minimax/MiniMax-M2.7",
        "--light-context",
        "--thinking",
        "low",
    ]
    print("Registering cron; message length:", len(msg), "bytes", file=sys.stderr)
    subprocess.check_call(cmd)


if __name__ == "__main__":
    main()
