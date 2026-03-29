#!/usr/bin/env python3
"""Register the daily news email cron on the OpenClaw gateway (run as openclaw user)."""
import pathlib
import subprocess
import sys

msg_path = pathlib.Path("/home/openclaw/.openclaw/workspace/cron-daily-news-email-message.txt")
msg = msg_path.read_text(encoding="utf-8")
cmd = [
    "openclaw",
    "cron",
    "add",
    "--name",
    "Daily news email (Shanghai 7am)",
    "--cron",
    "0 7 * * *",
    "--tz",
    "Asia/Shanghai",
    "--session",
    "isolated",
    "--no-deliver",
    "--timeout-seconds",
    "900",
    "--message",
    msg,
]
print("Registering cron; message length:", len(msg), file=sys.stderr)
subprocess.check_call(cmd)
