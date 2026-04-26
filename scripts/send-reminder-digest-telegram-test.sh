#!/usr/bin/env bash
# Run on the gateway as openclaw: send reminder.py digest to Telegram (manual test).
set -euo pipefail
PY="/home/openclaw/.openclaw/workspace/skills/reminder/scripts/reminder.py"
TO="7046769291"
TEXT="$(python3 "$PY" digest)"
# Telegram hard limit ~4096; keep a margin
if ((${#TEXT} > 3800)); then
  TEXT="${TEXT:0:3800}"$'\n'"…(truncated)"
fi
exec openclaw message send --channel telegram -t "$TO" -m "$TEXT"
