#!/usr/bin/env python3
"""Patch OpenClaw cron job message from workspace file. Args: job_id path_to_message_file"""
import pathlib
import subprocess
import sys

job_id = sys.argv[1]
msg = pathlib.Path(sys.argv[2]).read_text(encoding="utf-8")
subprocess.check_call(["openclaw", "cron", "edit", job_id, "--message", msg])
