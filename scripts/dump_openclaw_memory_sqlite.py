#!/usr/bin/env python3
"""Dump OpenClaw memory SQLite for inspection (run on Gateway host)."""
import sqlite3
import sys

path = sys.argv[1] if len(sys.argv) > 1 else "/home/openclaw/.openclaw/memory/main.sqlite"
conn = sqlite3.connect(path)
conn.row_factory = sqlite3.Row
cur = conn.cursor()
tables = [r[0] for r in cur.execute("SELECT name FROM sqlite_master WHERE type=? ORDER BY name", ("table",))]
print("Tables:", ", ".join(tables) if tables else "(none)")
for name in tables:
    print("\n===", name, "===")
    rows = cur.execute(f'SELECT * FROM "{name}"').fetchall()
    if not rows:
        print("(empty)")
        continue
    keys = rows[0].keys()
    for row in rows:
        print(dict(zip(keys, row)))
