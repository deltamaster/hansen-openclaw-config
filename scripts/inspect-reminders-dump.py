#!/usr/bin/env python3
"""Run on gateway: print reminders for debugging. Usage: python3 inspect-reminders-dump.py"""
import os, json, sqlite3, datetime, pytz, unicodedata, re
DB = os.path.expanduser("~/.openclaw/workspace/data/reminders.db")
CH = pytz.timezone("Asia/Shanghai")
def ntitle(t):
  if t is None:
    return ""
  t = unicodedata.normalize("NFKC", t).strip()
  return re.sub(r"\s+", " ", t)
def ts(x):
  return datetime.datetime.fromtimestamp(x, tz=CH).strftime("%Y-%m-%d %H:%M")
conn = sqlite3.connect(DB)
conn.row_factory = sqlite3.Row
now = int(datetime.datetime.now(CH).timestamp())
for r in conn.execute("SELECT * FROM reminders ORDER BY id").fetchall():
  d = {k: r[k] for k in r.keys()}
  d["_norm_title"] = ntitle(r["title"])
  d["event_local"] = ts(r["event_timestamp"])
  d["in_future"] = r["event_timestamp"] >= now
  print(json.dumps(d, ensure_ascii=False))
print("--- now", ts(now), "count", conn.execute("SELECT count(*) c FROM reminders").fetchone()["c"])
conn.close()
