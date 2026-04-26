---
name: travel
description: >
  Manage Hansen's travel visit records AND flight records in local SQLite databases.
  Use when: (1) user shares a travel experience to record, (2) user asks about past visits,
  (3) user asks about flight history, (4) user wants to see travel or flight statistics.
  Two databases: travel.db (visits) and flights.db (flights).
---

# Travel + Flights Skill

Two databases:
- `~/.openclaw/workspace/data/travel.db` — visit records
- `~/.openclaw/workspace/data/flights.db` — flight records

## CLI: travel.py

```bash
# === TRAVEL ===
python3 travel.py travel add "2026-04-22" "广西" "北海市" "银滩" "景点"   # add visit
python3 travel.py travel list 20                                        # list visits
python3 travel.py travel list 10 "广西"                               # filter by province/city
python3 travel.py travel stats                                        # travel statistics
python3 travel.py travel update 1 rating 5                            # update a field
python3 travel.py travel image_add 1 "/path/to/img.jpg" "description" # add image to visit

# === FLIGHTS ===
python3 travel.py flight add "2026-04-18" "东方航空" "MU6399" "上海浦东" "15:35" "北海福成" "18:55" 1670   # add flight
python3 travel.py flight search "埃塞俄比亚"                         # search flights
python3 travel.py flight stats                                        # flight statistics
```

## Workflow: Record New Visit

**⚠️ CRITICAL: Only record what the user EXPLICITLY provides. Never infer.**
1. Extract ONLY: date, province, city, attraction name, type
2. If a field is NOT mentioned → leave it NULL
3. Run `travel.py travel add ...`
4. Confirm only the actual fields filled

## Workflow: Record New Flight

1. Extract: date, airline, flight_number, departure_city, departure_time, arrival_city, arrival_time, distance_km
2. Run `travel.py flight add ...`

## Workflow: Query

- "我去过哪些地方" → `travel.py travel stats`
- "我在浙江去过哪些地方" → `travel.py travel list 50 "浙江"`
- "我坐过哪些航班" → `travel.py flight stats`
- "我飞过埃塞俄比亚吗" → `travel.py flight search "埃塞俄比亚"`
