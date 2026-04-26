---
name: portfolio
description: >
  Manage Hansen's investment portfolio stored in a local SQLite database.
  Use when: (1) user shares a new portfolio screenshot to update holdings, (2) user asks to see current positions or historical snapshots, (3) user wants to record a new position, (4) user asks for portfolio summary or performance analysis.
  Common tasks: update holdings from screenshot, take portfolio snapshot, show history, query by bank/issuer/risk level.
---

# Portfolio Skill

Hansen stores investment holdings in `~/.openclaw/workspace/data/portfolio.db` (SQLite).

## Quick Commands

```bash
# View summary
python3 ~/.openclaw/workspace/skills/portfolio/scripts/portfolio.py summary

# Take a snapshot (records today's values)
python3 ~/.openclaw/workspace/skills/portfolio/scripts/portfolio.py snapshot

# View historical snapshots
python3 ~/.openclaw/workspace/skills/portfolio/scripts/portfolio.py history

# Show full table (all 28 fields)
python3 ~/.openclaw/workspace/skills/portfolio/scripts/portfolio.py full

# Show all snapshots
python3 ~/.openclaw/workspace/skills/portfolio/scripts/portfolio.py history
```

## Workflow: Update from Screenshot

1. User sends portfolio screenshot → use `image` tool to extract all positions
2. For each position, determine if it already exists (match by `name` or `name_en`)
   - **Existing**: UPDATE with new values (current_value, unrealized_pnl, unrealized_pct, cash_dividend, total_return, total_return_pct)
   - **New**: INSERT full row
3. After DB update, run `portfolio.py snapshot` to record today's snapshot
4. Confirm update to user with summary

## Workflow: Add New Position

```python
python3 portfolio.py add "基金名称" "英文名" "ticker" "fund" "发行机构" "银行" 风险等级 "CNY" 份额 买入净值 当前净值 买入金额 当前市值 未实现额 未实现% 股息 总回报 总回报% [票息] [knockin] [autocall] [strike] [maturity] [买入日期] "备注"
```

## Schema (28 fields)

| Field | Type | Notes |
|-------|------|-------|
| id | INTEGER | PK |
| name | TEXT | Chinese name (required) |
| name_en | TEXT | English name |
| ticker | TEXT | Fund code / ticker |
| asset_type | TEXT | fund / stock / structured_deposit / bond / etf |
| issuer | TEXT | Fund house / issuer |
| bank | TEXT | Purchase channel (required) |
| risk_level | INTEGER | 1-5 risk scale |
| currency | TEXT | CNY / HKD / USD |
| quantity | REAL | Shares / units |
| purchase_nav | REAL | Purchase NAV |
| current_nav | REAL | Current NAV |
| purchase_amount | REAL | Total purchase cost |
| current_value | REAL | Current market value (required) |
| unrealized_pnl | REAL | Unrealized P&L |
| unrealized_pct | REAL | Unrealized return % |
| cash_dividend | REAL | Cash dividends received |
| total_return | REAL | Total return (P&L + dividend) |
| total_return_pct | REAL | Total return % |
| coupon_rate | REAL | For structured deposits |
| knockin_level | REAL | For structured deposits |
| autocall_level | REAL | For structured deposits |
| strike_level | REAL | For structured deposits |
| maturity | TEXT | Maturity date |
| purchase_date | TEXT | Purchase date |
| notes | TEXT | Free text notes |
| created_at | TEXT | Auto |
| updated_at | TEXT | Auto |

Also: `snapshots` table tracks historical portfolio values.

## Tips

- When updating, always match by `name` — don't create duplicates
- `snapshot` appends to `snapshots` table with today's date automatically
- NULL fields (ticker, purchase_*, maturity, coupon fields) can be updated later if user provides them
