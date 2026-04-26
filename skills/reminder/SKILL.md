---
name: reminder
description: >
  Manage Hansen's reminders and schedules. Use when: (1) user wants to add a future appointment/trip
  with time/location/details, (2) user asks about upcoming reminders, (3) a cron job checks every 15 min
  and delivers due reminders to WeChat (微信). Records event details, lead time for reminders, and handles delivery.
---

# Reminder Skill

Reminder database: `~/.openclaw/workspace/data/reminders.db`

## CLI

```bash
# Add reminder: title description event_date event_time location lead_minutes
python3 reminder.py add "北京出差" "见客户，讨论项目" "2026-05-01" "10:00" "北京CBD" 60

# List all reminders
python3 reminder.py list all

# List pending (future) reminders
python3 reminder.py list pending

# Upcoming events digest (default: next 30 days) — for morning Telegram cron
python3 reminder.py digest
python3 reminder.py digest --days=14

# Delete a reminder
python3 reminder.py delete 1

# Check for due reminders (used by cron)
python3 reminder.py check
```

## Fields

| Field | Notes |
|-------|-------|
| title | What |
| description | Details |
| event_date | Date (YYYY-MM-DD) |
| event_time | Time (HH:MM) |
| location | Where |
| lead_minutes | How many minutes before to remind |
| repeat | once / daily / weekly / monthly |

## Cron

A cron job runs every 15 minutes (minimax model) that:
1. Runs `reminder.py check`
2. If reminders are due → sends to WeChat (微信) via `openclaw message send`
3. If none due → replies HEARTBEAT_OK

**Morning digest (07:30 Asia/Shanghai, Telegram):** OpenClaw scheduled job calls `reminder.py digest` and posts the result to Telegram (same delivery pattern as the daily news cron). Register on the gateway from this repo: `python3 scripts/register-reminder-morning-cron.py` (after copying `scripts/reminder-morning-cron-message.txt` next to the register script; message body also lives in [`scripts/reminder-morning-cron-message.txt`](../../scripts/reminder-morning-cron-message.txt)).

## Workflow: Add Reminder

**⚠️ STEP 1: THINK BEFORE RECORDING**

Before adding any reminder, ask yourself:
1. **What TYPE of event is this?** (train / flight / hotel check-in / meeting / appointment / other)
2. **When do I need to take an action? Decide the lead time appropriately. Set multiple reminders when I need to take multiple actions at different times. Ask for information if necessary, e.g.:**
   1. From where will I set off if I need to travel physically to the venue?
   2. What should I prepare before I attend a meeting?
   3. Who is the contact person if there is any?
3. **What KEY INFORMATION do I need to see in order to take the action when this reminder fires?**
4. **What FORMAT will be clearest at a glance?**

**事件类型决定提醒格式：**

| 事件类型 | 必须包含的关键信息 | 提醒格式建议 |
|---------|-----------------|-------------|
| 🚃 火车 | 车次、出发站→到达站、出发时间→到达时间、座位 | 一行搞定，含全部关键 |
| ✈️ 航班 | 航班号、出发→到达、起飞→到达、舱位 | 同上 |
| 🏨 酒店入住 | 酒店名称、地址、入住日期 | 到店时间+地址+导航 |
| 🚖 接人/送人 | 车牌、车型、颜色、联系人电话 | 车牌优先 |
| 🏥 医院/门诊 | 科室、医生、挂号时间 | 时间+科室+医生 |
| 💼 会议 | 时间、地点、议题 | 时间地点优先 |

**Step 2: Extract and confirm**
1. Identify the event type from context
2. Pull all relevant fields for that event type
3. Format the description as a single clear line that tells Hansen everything he needs to know at a glance — without opening the app
4. Run `reminder.py add ...`
5. Confirm to user with the reminder details

**Reminder description原则:**
- 收到提醒时，**不需要再打开任何app**就能知道接下来做什么
- 把最重要的一行信息放description开头
- 座位号/地点/联系方式等实用信息要全

## ⚠️ Train/Flight Reminder Fields (CRITICAL)

When adding train or flight reminders, you MUST extract and include ALL of the following:
- **出发时间** (departure_time): e.g. 14:34
- **到达时间** (arrival_time): e.g. 15:41
- **出发站** (departure_station): e.g. 北海 / 南宁 / 南宁东 — DIFFERENT STATIONS!
- **到达站** (arrival_station): e.g. 南宁 / 南宁东 — CHECK CAREFULLY!
- **车次**: e.g. D3926 / G1234
- **座位号** (seat): 格式为「X等座 X车XX座」，例如「二等座 5车13A」「一等座 3车07F」

**Examples of what to look for:**
- "北海→南宁" vs "北海→南宁东" are DIFFERENT destinations!
- D3926 goes to 南宁站, D368 goes to 南宁东站
- Always confirm: departure station AND arrival station separately
- Where will the user be before the event? How long does it take for the user to travel to the venue?

**Reminder message format:**
```
[车次] 出发时间 出发站 → 到达站 到达时间
座位: [座位号]
```

## Workflow: Query

- "我有什么提醒" → `reminder.py list pending`
- "X号的行程" → `reminder.py list pending` and filter
- "帮我看看明天有什么行程" → check reminders for tomorrow's date
