#!/usr/bin/env python3
"""Run on gateway: update daily news cron — Telegram only, no email."""
import subprocess

JOB_ID = "f144d536-8ca7-499e-bcf2-9cfb96478154"
MSG_PATH = "/tmp/daily-news-cron-message.txt"

def main() -> None:
    msg = open(MSG_PATH, encoding="utf-8").read()
    subprocess.run(
        [
            "openclaw",
            "cron",
            "edit",
            JOB_ID,
            "--name",
            "Daily news (Telegram, Shanghai 7am)",
            "--message",
            msg,
            "--channel",
            "telegram",
            "--to",
            "7046769291",
            "--model",
            "deepseek/deepseek-reasoner",
            "--enable",
        ],
        check=True,
    )


if __name__ == "__main__":
    main()
