#!/usr/bin/env python3
"""Portfolio DB management CLI"""
import sqlite3, sys, os, datetime

DB = os.path.expanduser('~/.openclaw/workspace/data/portfolio.db')
os.makedirs(os.path.dirname(DB), exist_ok=True)

def get_conn():
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    return conn

def init_db():
    conn = get_conn()
    c = conn.cursor()
    schema = '''
    CREATE TABLE IF NOT EXISTS holdings (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        name            TEXT    NOT NULL,
        name_en         TEXT,
        ticker          TEXT,
        asset_type      TEXT    NOT NULL,
        issuer          TEXT,
        bank            TEXT    NOT NULL,
        risk_level      INTEGER,
        currency        TEXT    DEFAULT 'CNY',
        quantity        REAL,
        purchase_nav    REAL,
        current_nav     REAL,
        purchase_amount REAL,
        current_value   REAL,
        unrealized_pnl  REAL,
        unrealized_pct  REAL,
        cash_dividend   REAL    DEFAULT 0,
        total_return    REAL,
        total_return_pct REAL,
        coupon_rate     REAL,
        knockin_level   REAL,
        autocall_level  REAL,
        strike_level    REAL,
        maturity        TEXT,
        purchase_date   TEXT,
        notes           TEXT,
        created_at      TEXT    DEFAULT (datetime('now')),
        updated_at      TEXT    DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS snapshots (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        snapshot_date   TEXT    NOT NULL,
        total_value     REAL,
        total_pnl       REAL,
        total_dividend  REAL,
        total_return    REAL,
        created_at      TEXT    DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_holdings_asset_type ON holdings(asset_type);
    CREATE INDEX IF NOT EXISTS idx_holdings_bank ON holdings(bank);
    CREATE INDEX IF NOT EXISTS idx_holdings_risk ON holdings(risk_level);
    '''
    c.executescript(schema)
    conn.commit()
    conn.close()

def cmd_summary():
    conn = get_conn()
    c = conn.cursor()
    c.execute('SELECT COUNT(*), SUM(current_value), SUM(unrealized_pnl), SUM(cash_dividend), SUM(total_return) FROM holdings')
    row = c.fetchone()
    print(f'\n=== 持仓汇总 ===')
    print(f'基金数量:   {row[0]}')
    print(f'总市值:     {row[1]:>12,.2f} CNY')
    print(f'未实现盈亏: {row[2]:>+12,.2f} CNY')
    print(f'现金股息:   {row[3]:>+12,.2f} CNY')
    print(f'总回报:     {row[4]:>+12,.2f} CNY')
    if row[1]:
        print(f'总回报率:   {row[4]/row[1]*100:>+11.2f}%')
    print()
    c.execute('SELECT name, bank, risk_level, current_value, unrealized_pct, total_return_pct FROM holdings ORDER BY current_value DESC')
    fmt = f'{"名称":<28} {"银行":<5} {"风险":<4} {"市值":>12} {"未实现%":>9} {"总回报%":>9}'
    print(fmt)
    print('-' * 80)
    for r in c.fetchall():
        print(f'{r[0]:<28} {r[1]:<5} {r[2]:<4} {r[3]:>12,.2f} {r[4]:>+8.2f}% {r[5]:>+8.2f}%')
    conn.close()

def cmd_full():
    conn = get_conn()
    c = conn.cursor()
    c.execute('SELECT * FROM holdings')
    rows = c.fetchall()
    cols = [d[0] for d in c.description]
    print(f'\n=== 完整持仓 ({len(rows)}行, {len(cols)}字段) ===\n')
    # Print header
    print(" | ".join(f'{c}' for c in cols))
    print("-" * 150)
    for row in rows:
        print(" | ".join(f'{row[c]}' for c in cols))
    print("-" * 150)
    conn.close()

def cmd_snapshot():
    conn = get_conn()
    c = conn.cursor()
    c.execute('SELECT SUM(current_value), SUM(unrealized_pnl), SUM(cash_dividend), SUM(total_return) FROM holdings')
    row = c.fetchone()
    today = datetime.date.today().isoformat()
    c.execute('INSERT INTO snapshots (snapshot_date, total_value, total_pnl, total_dividend, total_return) VALUES (?,?,?,?,?)',
              (today, row[0], row[1], row[2], row[3]))
    conn.commit()
    print(f'Snapshot saved: {today} | 总市值: {row[0]:,.2f} | 总回报: {row[3]:>+,.2f} CNY')
    conn.close()

def cmd_history():
    conn = get_conn()
    c = conn.cursor()
    c.execute('SELECT snapshot_date, total_value, total_pnl, total_dividend, total_return FROM snapshots ORDER BY snapshot_date DESC')
    rows = c.fetchall()
    if not rows:
        print('No snapshots yet. Run: portfolio.py snapshot')
        conn.close()
        return
    print('\n=== 历史快照 ===\n')
    print(f'{"日期":<12} {"总市值":>14} {"未实现盈亏":>12} {"股息":>10} {"总回报":>12}')
    print('-' * 65)
    for r in rows:
        print(f'{r[0]:<12} {r[1]:>14,.2f} {r[2]:>+12,.2f} {r[3]:>+10,.2f} {r[4]:>+12,.2f}')
    conn.close()

def cmd_add(args):
    """Add a new holding: name name_en ticker asset_type issuer bank risk currency qty pnav cnav pamt cval upnl upct div tret tretpct [coupon ki ac strike maturity pdate notes]"""
    init_db()
    conn = get_conn()
    c = conn.cursor()
    cols = ['name','name_en','ticker','asset_type','issuer','bank','risk_level','currency',
            'quantity','purchase_nav','current_nav','purchase_amount','current_value',
            'unrealized_pnl','unrealized_pct','cash_dividend','total_return','total_return_pct',
            'coupon_rate','knockin_level','autocall_level','strike_level','maturity','purchase_date','notes']
    sql = 'INSERT INTO holdings (' + ','.join(cols) + ') VALUES (' + ','.join(['?']*len(cols)) + ')'
    c.execute(sql, args)
    conn.commit()
    print(f'Added: {args[0]}')
    conn.close()

def cmd_update(name, current_value, unrealized_pnl, unrealized_pct, cash_dividend, total_return, total_return_pct):
    """Update a holding by name"""
    conn = get_conn()
    c = conn.cursor()
    c.execute('''UPDATE holdings SET
        current_value=?, unrealized_pnl=?, unrealized_pct=?,
        cash_dividend=?, total_return=?, total_return_pct=?,
        updated_at=datetime('now')
        WHERE name=?''', (current_value, unrealized_pnl, unrealized_pct, cash_dividend, total_return, total_return_pct, name))
    conn.commit()
    if c.rowcount == 0:
        print(f'Warning: no row updated for "{name}" — may not exist')
    else:
        print(f'Updated: {name}')
    conn.close()

def cmd_delete(name):
    conn = get_conn()
    c = conn.cursor()
    c.execute('DELETE FROM holdings WHERE name=?', (name,))
    conn.commit()
    print(f'Deleted: {name} (rows affected: {c.rowcount})')
    conn.close()

if __name__ == '__main__':
    init_db()
    if len(sys.argv) < 2:
        print('Usage: portfolio.py [summary|full|snapshot|history|add|update|delete]')
        sys.exit(1)
    cmd = sys.argv[1]
    if cmd == 'summary':
        cmd_summary()
    elif cmd == 'full':
        cmd_full()
    elif cmd == 'snapshot':
        cmd_snapshot()
    elif cmd == 'history':
        cmd_history()
    elif cmd == 'add':
        if len(sys.argv) < 9:
            print('Usage: portfolio.py add <name> <name_en> <ticker> <asset_type> <issuer> <bank> <risk> <currency> ...')
            sys.exit(1)
        cmd_add(sys.argv[2:])
    elif cmd == 'update':
        # portfolio.py update "Name" current_value unrealized_pnl unrealized_pct cash_dividend total_return total_return_pct
        name = sys.argv[2]
        vals = [float(x) for x in sys.argv[3:]]
        cmd_update(name, *vals)
    elif cmd == 'delete':
        cmd_delete(sys.argv[2])
    else:
        print(f'Unknown: {cmd}')
        sys.exit(1)
