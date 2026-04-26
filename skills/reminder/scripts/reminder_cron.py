#!/usr/bin/env python3
"""Reminder cron checker - deletes expired + finds due reminders"""
import sqlite3, os, datetime, pytz

DB = os.path.expanduser('~/.openclaw/workspace/data/reminders.db')
CHINA_TZ = pytz.timezone('Asia/Shanghai')

conn = sqlite3.connect(DB)
conn.row_factory = sqlite3.Row
c = conn.cursor()

now = datetime.datetime.now(CHINA_TZ)
now_ts = int(now.timestamp())

# 1. Delete expired
c.execute('SELECT * FROM reminders WHERE is_active=1 AND repeat="once"')
deleted = 0
for r in c.fetchall():
    evt = datetime.datetime.fromtimestamp(r['event_timestamp'], tz=CHINA_TZ)
    if evt + datetime.timedelta(minutes=1) < now and r['last_reminded'] is not None:
        conn.execute('DELETE FROM reminders WHERE id=?', (r['id'],))
        deleted += 1
conn.commit()

# 2. Find due
c.execute('SELECT * FROM reminders WHERE is_active=1 AND repeat="once" ORDER BY event_timestamp')
due = []
for r in c.fetchall():
    evt = datetime.datetime.fromtimestamp(r['event_timestamp'], tz=CHINA_TZ)
    lead_before_ts = int((evt - datetime.timedelta(minutes=r['lead_minutes'])).timestamp())
    if lead_before_ts <= now_ts <= int(evt.timestamp()) + 60:
        if r['last_reminded']:
            mins_since = (now_ts - r['last_reminded']) / 60
            if mins_since < r['lead_minutes']:
                continue
        due.append(r)

for r in due:
    conn.execute('UPDATE reminders SET last_reminded=? WHERE id=?', (now_ts, r['id']))
conn.commit()
conn.close()

if deleted > 0:
    print('Cleaned ' + str(deleted) + ' expired')
if not due:
    print('NONE')
else:
    print('DUE:' + str(len(due)))
    for r in due:
        evt = datetime.datetime.fromtimestamp(r['event_timestamp'], tz=CHINA_TZ)
        print('ID=' + str(r['id']) + '|' + evt.strftime('%Y-%m-%d') + '|' + evt.strftime('%H:%M') + '|' + r['title'] + '|' + (r['description'] or '') + '|' + (r['location'] or ''))
