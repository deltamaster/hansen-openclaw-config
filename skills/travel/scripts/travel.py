#!/usr/bin/env python3
"""Travel + Flights management CLI"""
import sqlite3, sys, os

TRAVEL_DB = os.path.expanduser('~/.openclaw/workspace/data/travel.db')
FLIGHTS_DB = os.path.expanduser('~/.openclaw/workspace/data/flights.db')

def get_conn(db):
    conn = sqlite3.connect(db)
    conn.row_factory = sqlite3.Row
    return conn

def init_dbs():
    td = get_conn(TRAVEL_DB)
    td.execute('''CREATE TABLE IF NOT EXISTS visits (id INTEGER PRIMARY KEY AUTOINCREMENT, date TEXT NOT NULL, province TEXT NOT NULL, city TEXT NOT NULL, attraction TEXT NOT NULL, attraction_en TEXT, type TEXT DEFAULT '景点', country TEXT DEFAULT '中国', rating INTEGER, cost REAL, cost_currency TEXT DEFAULT 'CNY', thoughts TEXT, highlights TEXT, tips TEXT, revisit INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))''')
    td.execute('''CREATE INDEX IF NOT EXISTS idx_date ON visits(date)''')
    td.execute('''CREATE INDEX IF NOT EXISTS idx_province ON visits(province)''')
    td.execute('''CREATE INDEX IF NOT EXISTS idx_city ON visits(city)''')
    td.execute('''CREATE INDEX IF NOT EXISTS idx_type ON visits(type)''')
    td.commit()
    td.close()
    fd = get_conn(FLIGHTS_DB)
    fd.execute('''CREATE TABLE IF NOT EXISTS flights (id INTEGER PRIMARY KEY AUTOINCREMENT, flight_date TEXT NOT NULL, airline TEXT NOT NULL, flight_number TEXT NOT NULL, departure_city TEXT NOT NULL, departure_time TEXT, arrival_city TEXT NOT NULL, arrival_time TEXT, distance_km REAL, ticket_no TEXT, status TEXT DEFAULT '已使用', created_at TEXT DEFAULT (datetime('now')))''')
    fd.execute('''CREATE INDEX IF NOT EXISTS idx_flight_date ON flights(flight_date)''')
    fd.execute('''CREATE INDEX IF NOT EXISTS idx_airline ON flights(airline)''')
    fd.execute('''CREATE INDEX IF NOT EXISTS idx_departure ON flights(departure_city)''')
    fd.execute('''CREATE INDEX IF NOT EXISTS idx_arrival ON flights(arrival_city)''')
    fd.commit()
    fd.close()

# === TRAVEL COMMANDS ===

def cmd_travel_add(args):
    conn = get_conn(TRAVEL_DB)
    c = conn.cursor()
    cols = ['date','province','city','attraction','type']
    c.execute('INSERT INTO visits (' + ','.join(cols) + ') VALUES (?,?,?,?,?)', args[:5])
    conn.commit()
    print('Added visit:', args[2], args[3])
    conn.close()

def cmd_travel_list(args):
    conn = get_conn(TRAVEL_DB)
    c = conn.cursor()
    limit = int(args[0]) if args else 20
    if len(args) > 1:
        c.execute('SELECT * FROM visits WHERE province LIKE ? OR city LIKE ? ORDER BY date DESC LIMIT ?',
                  (f'%{args[1]}%', f'%{args[1]}%', limit))
    else:
        c.execute('SELECT * FROM visits ORDER BY date DESC LIMIT ?', (limit,))
    rows = c.fetchall()
    print(f'\n=== 旅行记录 ({len(rows)}条) ===\n')
    for r in rows:
        rating = '⭐' * r['rating'] if r['rating'] else ''
        print(f"  {r['date']} | {r['province']}{r['city']} | {r['attraction']} {rating}")
        if r['thoughts']:
            print(f"    {r['thoughts']}")
    conn.close()

def cmd_travel_stats():
    conn = get_conn(TRAVEL_DB)
    c = conn.cursor()
    c.execute('SELECT COUNT(*) FROM visits')
    total = c.fetchone()[0]
    c.execute('SELECT COUNT(DISTINCT province) FROM visits')
    provinces = c.fetchone()[0]
    c.execute('SELECT COUNT(DISTINCT city) FROM visits')
    cities = c.fetchone()[0]
    print(f'\n=== 旅行统计 ===\n总记录: {total}条 | {provinces}省 | {cities}城')
    c.execute('SELECT province, COUNT(*) as cnt FROM visits GROUP BY province ORDER BY cnt DESC')
    print('\n各省份:')
    for r in c.fetchall():
        print(f'  {r[0]}: {r[1]}条')
    conn.close()

def cmd_travel_update(args):
    conn = get_conn(TRAVEL_DB)
    c = conn.cursor()
    id, field, value = args[0], args[1], args[2]
    allowed = ['thoughts','rating','highlights','tips','revisit','type','province','city','attraction']
    if field not in allowed:
        print(f'Allowed: {", ".join(allowed)}')
        conn.close()
        return
    if field == 'revisit':
        value = 1 if value in ['1','true','yes'] else 0
    try:
        value = int(value)
    except:
        pass
    c.execute(f'UPDATE visits SET {field}=?, updated_at=datetime("now") WHERE id=?', (value, id))
    conn.commit()
    print(f'Updated {id}: {field} = {value}')
    conn.close()

# === FLIGHT COMMANDS ===

def cmd_flight_add(args):
    conn = get_conn(FLIGHTS_DB)
    c = conn.cursor()
    cols = ['flight_date','airline','flight_number','departure_city','departure_time','arrival_city','arrival_time']
    vals = list(args[:7])
    sql = 'INSERT INTO flights (' + ','.join(cols[:len(vals)]) + ') VALUES (' + ','.join(['?']*len(vals)) + ')'
    c.execute(sql, vals)
    conn.commit()
    print('Added flight:', vals[0], vals[1], vals[2], vals[3], '->', vals[5])
    conn.close()

def cmd_flight_search(args):
    conn = get_conn(FLIGHTS_DB)
    c = conn.cursor()
    if args:
        k = f'%{args[0]}%'
        c.execute('SELECT flight_date, airline, flight_number, departure_city, departure_time, arrival_city, arrival_time, distance_km FROM flights WHERE airline LIKE ? OR flight_number LIKE ? OR departure_city LIKE ? OR arrival_city LIKE ? ORDER BY flight_date DESC LIMIT 30', (k, k, k, k))
    else:
        c.execute('SELECT flight_date, airline, flight_number, departure_city, departure_time, arrival_city, arrival_time, distance_km FROM flights ORDER BY flight_date DESC LIMIT 20')
    rows = c.fetchall()
    print(f'\n=== 航班 ({len(rows)}条) ===\n')
    for r in rows:
        print(f"  {r['flight_date']} | {r['airline']} {r['flight_number']} | {r['departure_city']}({r['departure_time']}) -> {r['arrival_city']}({r['arrival_time']}) | {r['distance_km']}km")
    conn.close()

def cmd_flight_stats():
    conn = get_conn(FLIGHTS_DB)
    c = conn.cursor()
    c.execute('SELECT COUNT(*), SUM(distance_km) FROM flights')
    r = c.fetchone()
    print(f'\n=== 航班统计 ===\n总航班: {r[0]}次 | 总里程: {r[1]:,.0f}km')
    c.execute('SELECT airline, COUNT(*) FROM flights GROUP BY airline ORDER BY COUNT(*) DESC')
    print('\n航司:')
    for r in c.fetchall():
        print(f'  {r[0]}: {r[1]}次')
    c.execute('SELECT COUNT(DISTINCT departure_city) FROM flights')
    print(f'\n出发城市: {c.fetchone()[0]}')
    c.execute('SELECT COUNT(DISTINCT arrival_city) FROM flights')
    print(f'到达城市: {c.fetchone()[0]}')
    conn.close()

# === MAIN ===

if __name__ == '__main__':
    init_dbs()
    if len(sys.argv) < 2:
        print('Usage: travel.py [travel|flight] [add|list|stats|search|update] [args]')
        sys.exit(1)
    mode = sys.argv[1]
    if mode == 'travel':
        if len(sys.argv) < 3:
            cmd_travel_stats()
        elif sys.argv[2] == 'add':
            cmd_travel_add(sys.argv[3:])
        elif sys.argv[2] == 'list':
            cmd_travel_list(sys.argv[3:])
        elif sys.argv[2] == 'stats':
            cmd_travel_stats()
        elif sys.argv[2] == 'update':
            cmd_travel_update(sys.argv[3:])
        elif sys.argv[2] == 'image_add':
            cmd_travel_image_add(sys.argv[3:])
    elif mode == 'flight':
        if len(sys.argv) < 3:
            cmd_flight_stats()
        elif sys.argv[2] == 'add':
            cmd_flight_add(sys.argv[3:])
        elif sys.argv[2] == 'search':
            cmd_flight_search(sys.argv[3:])
        elif sys.argv[2] == 'stats':
            cmd_flight_stats()
    else:
        print(f'Unknown: {sys.argv[1]}')

def cmd_travel_image_add(args):
    if len(args) < 3:
        print("Usage: travel.py travel image_add <visit_id> <file_path> <description>")
        return
    visit_id, file_path, desc = args[0], args[1], args[2]
    import shutil
    import os
    import uuid
    
    if not os.path.exists(file_path):
        print(f"Error: file not found {file_path}")
        return
        
    dest_dir = '/home/openclaw/.openclaw/workspace/data/travel_images'
    os.makedirs(dest_dir, exist_ok=True)
    _, ext = os.path.splitext(file_path)
    filename = f"img_{uuid.uuid4().hex[:8]}{ext}"
    dest_path = os.path.join(dest_dir, filename)
    shutil.copy(file_path, dest_path)
    
    conn = get_conn(TRAVEL_DB)
    conn.execute('''
    CREATE TABLE IF NOT EXISTS visit_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visit_id INTEGER NOT NULL,
        file_path TEXT NOT NULL,
        width INTEGER,
        height INTEGER,
        description TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (visit_id) REFERENCES visits(id)
    )
    ''')
    conn.execute('INSERT INTO visit_images (visit_id, file_path, description) VALUES (?, ?, ?)', (visit_id, dest_path, desc))
    conn.commit()
    conn.close()
    print(f"Added image to visit {visit_id}: {filename}")
