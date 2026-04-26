#!/usr/bin/env python3
"""Reminder management CLI with one-shot cron per reminder"""
import sqlite3, sys, os, datetime, pytz, subprocess, json

DB = os.path.expanduser('~/.openclaw/workspace/data/reminders.db')
CHINA_TZ = pytz.timezone('Asia/Shanghai')

def get_conn():
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    return conn

def init_db():
    conn = get_conn()
    conn.execute('''CREATE TABLE IF NOT EXISTS reminders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT,
        event_timestamp INTEGER NOT NULL,
        location TEXT,
        lead_minutes INTEGER DEFAULT 30,
        repeat TEXT DEFAULT 'once',
        is_active INTEGER DEFAULT 1,
        last_reminded INTEGER,
        cron_id TEXT,
        created_at INTEGER DEFAULT (CAST(strftime('%s', 'now') AS INTEGER)),
        note TEXT
    )''')
    conn.commit()
    conn.close()

def dt_to_ts(dt_str):
    try:
        naive = datetime.datetime.strptime(dt_str, '%Y-%m-%d %H:%M')
    except:
        try:
            naive = datetime.datetime.strptime(dt_str, '%Y-%m-%d')
        except:
            return None
    return int(CHINA_TZ.localize(naive).timestamp())

def ts_to_str(ts):
    return datetime.datetime.fromtimestamp(ts, tz=CHINA_TZ).strftime('%Y-%m-%d %H:%M')

def create_one_shot_cron(reminder_id, trigger_ts, title, description, location):
    desc_esc = (description or '').replace('"', "'")
    loc_esc = (location or '').replace('"', "'")
    trigger_dt = datetime.datetime.fromtimestamp(trigger_ts, tz=CHINA_TZ)
    trigger_iso = trigger_dt.strftime('%Y-%m-%dT%H:%M:%S+08:00')
    msg = (
        "你是Hansen's reminder agent。有一条提醒到期：\n"
        "标题: " + title + "\n"
        "时间: " + ts_to_str(trigger_ts) + "\n"
        "描述: " + desc_esc + "\n"
        "地点: " + loc_esc + "\n\n"
        '执行以下命令发送Telegram:\n'
        'openclaw message send --channel telegram --target 7046769291 '
        '--message "🎯 提醒：' + title + '\\n📅 ' + ts_to_str(trigger_ts) + '\\n' + desc_esc + '"'
    )
    result = subprocess.run([
        'openclaw', 'cron', 'add',
        '--name', 'Reminder ' + str(reminder_id),
        '--at', trigger_iso,
        '--delete-after-run',
        '--session', 'isolated',
        '--timeout-seconds', '60',
        '--message', msg
    ], capture_output=True, text=True, timeout=30)
    if result.returncode != 0:
        return None
    try:
        return json.loads(result.stdout).get('id')
    except:
        return None

def cancel_cron(cron_id):
    subprocess.run(['openclaw', 'cron', 'delete', cron_id], capture_output=True)

def cmd_add(args):
    init_db()
    title = args[0]
    description = args[1] if len(args) > 1 else None
    event_ts = dt_to_ts(args[2])
    lead_minutes = int(args[3]) if len(args) > 3 else 30
    location = args[4] if len(args) > 4 else None

    if not event_ts:
        print('Invalid datetime format: use YYYY-MM-DD or YYYY-MM-DD HH:MM')
        return

    conn = get_conn()
    c = conn.execute(
        'INSERT INTO reminders (title, description, event_timestamp, location, lead_minutes) VALUES (?,?,?,?,?)',
        (title, description, event_ts, location, lead_minutes))
    conn.commit()
    reminder_id = c.lastrowid
    conn.close()

    trigger_ts = event_ts - lead_minutes * 60
    cron_id = create_one_shot_cron(reminder_id, trigger_ts, title, description, location)

    if cron_id:
        conn = get_conn()
        conn.execute('UPDATE reminders SET cron_id=? WHERE id=?', (cron_id, reminder_id))
        conn.commit()
        conn.close()

    print('Added: ' + title + ' at ' + ts_to_str(event_ts) + ' (lead: ' + str(lead_minutes) + 'min)')
    if cron_id:
        print('  Cron: ' + cron_id + ' fires at ' + ts_to_str(trigger_ts))
    else:
        print('  Warning: cron creation failed')

def cmd_list(args):
    init_db()
    show_all = 'all' in args
    now_ts = int(datetime.datetime.now(CHINA_TZ).timestamp())
    conn = get_conn()

    if show_all:
        c = conn.execute('SELECT * FROM reminders ORDER BY event_timestamp')
    else:
        c = conn.execute('SELECT * FROM reminders WHERE is_active=1 AND event_timestamp>=? ORDER BY event_timestamp', (now_ts,))

    rows = c.fetchall()
    if not rows:
        print('No reminders.')
        conn.close()
        return

    print('\n=== Reminders (' + str(len(rows)) + '条) ===\n')
    for r in rows:
        evt_str = ts_to_str(r['event_timestamp'])
        trigger_str = ts_to_str(r['event_timestamp'] - r['lead_minutes'] * 60)
        status = ''
        if r['event_timestamp'] < now_ts and r['repeat'] == 'once':
            status = ' [已过期]'
        elif r['last_reminded']:
            status = ' [已提醒]'
        print('  id=' + str(r['id']) + ' | ' + evt_str + ' | ' + r['title'] + ' | 触发:' + trigger_str + status)
        if r['description']:
            print('         ' + r['description'])
        if r['location']:
            print('         ' + r['location'])
        if r['cron_id']:
            print('         cron: ' + r['cron_id'])
    conn.close()

def cmd_check():
    init_db()
    conn = get_conn()
    now_ts = int(datetime.datetime.now(CHINA_TZ).timestamp())
    c = conn.execute('SELECT * FROM reminders WHERE is_active=1 AND last_reminded IS NULL ORDER BY event_timestamp')
    due = []
    for r in c.fetchall():
        trigger_ts = r['event_timestamp'] - r['lead_minutes'] * 60
        if trigger_ts <= now_ts <= r['event_timestamp'] + 60:
            due.append(r)
    conn.close()

    if not due:
        print('NONE')
    else:
        print('DUE:' + str(len(due)))
        for r in due:
            print('ID=' + str(r['id']) + '|' + ts_to_str(r['event_timestamp']) + '|' + r['title'] + '|' + (r['description'] or '') + '|' + (r['location'] or ''))

def cmd_delete(id):
    init_db()
    conn = get_conn()
    r = conn.execute('SELECT cron_id FROM reminders WHERE id=?', (id,)).fetchone()
    if r and r['cron_id']:
        cancel_cron(r['cron_id'])
    conn.execute('DELETE FROM reminders WHERE id=?', (id,))
    conn.commit()
    print('Deleted id=' + id)
    conn.close()

if __name__ == '__main__':
    init_db()
    if len(sys.argv) < 2:
        cmd_list([])
    elif sys.argv[1] == 'add':
        cmd_add(sys.argv[2:])
    elif sys.argv[1] == 'list':
        cmd_list(sys.argv[2:])
    elif sys.argv[1] == 'check':
        cmd_check()
    elif sys.argv[1] == 'delete':
        if len(sys.argv) < 3:
            print('Usage: reminder.py delete <id>')
        else:
            cmd_delete(sys.argv[2])