#!/usr/bin/env bash
# Run on the FreshRSS host (same logic as check-freshrss-contentenhancement-log.ps1).
# Reads log.txt files from the freshrss container and aggregates:
#   - prefilter: proceed|drop|keep|error
#   - fullscan: proceed|keep|drop
#
# Usage: ./check-freshrss-contentenhancement-log.sh
#        HOURS=12 CONTAINER=freshrss ./check-freshrss-contentenhancement-log.sh

set -euo pipefail

HOURS="${HOURS:-24}"
CONTAINER="${CONTAINER:-freshrss}"
export HOURS

TMPPY=$(mktemp)
trap 'rm -f "$TMPPY"' EXIT

cat > "$TMPPY" << 'PY'
import os, re, sys
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime

hours = float(os.environ["HOURS"])
cutoff = datetime.now(timezone.utc) - timedelta(hours=hours)
raw = sys.stdin.read()
lines = raw.splitlines()

leader_re = re.compile(r"^\[([^\]]+)\]")
pref_re = re.compile(r"ContentEnhancement:\s+prefilter\s+(proceed|drop|keep|error)\s")
fs_re = re.compile(r"ContentEnhancement:\s+fullscan\s+(proceed|keep|drop)\s")
prompt_re = re.compile(r"\bprompt=(\d+)")
completion_re = re.compile(r"\bcompletion=(\d+)")
score_re = re.compile(r"\brelevance_score=(\d+|-)\b")


def parse_leader_ts(s: str):
    try:
        return datetime.strptime(s, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except ValueError:
        pass
    try:
        dt = parsedate_to_datetime(s)
        if dt.tzinfo is None:
            return dt.replace(tzinfo=timezone.utc)
        return dt.astimezone(timezone.utc)
    except (TypeError, ValueError, OverflowError):
        return None


pref = {"proceed": 0, "drop": 0, "keep": 0, "error": 0}
fs = {"proceed": 0, "keep": 0, "drop": 0}
skipped_no_ts = skipped_old = 0
sum_p_pre = sum_c_pre = 0
sum_p_fs = sum_c_fs = 0
lines_tok_pre = lines_miss_pre = 0
lines_tok_fs = lines_miss_fs = 0
scores_pre = []
scores_fs = []
miss_score_pre = miss_score_fs = 0
pref_scores = {k: [] for k in ("proceed", "drop", "keep", "error")}
fs_scores = {k: [] for k in ("proceed", "keep", "drop")}


def print_score_stats(title, scores, miss_dash, by_dec, dec_order):
    print()
    print(f"=== {title} ===")
    if not scores:
        print(f"  (no numeric relevance_score in window; missing/dash: {miss_dash})")
        return
    s = sorted(scores)
    n = len(s)
    mean = sum(s) / n
    med = s[n // 2] if n % 2 else (s[n // 2 - 1] + s[n // 2]) / 2.0
    hist = {i: 0 for i in range(1, 11)}
    other = 0
    for x in s:
        if 1 <= x <= 10:
            hist[x] += 1
        else:
            other += 1
    hstr = "  ".join(f"{i}={hist[i]}" for i in range(1, 11))
    print(f"  Lines with numeric score: {n}   (relevance_score=- or missing: {miss_dash})")
    print(f"  min / max / mean / median:  {s[0]} / {s[-1]} / {mean:.2f} / {med}")
    print(f"  Histogram (1-10):  {hstr}")
    if other:
        print(f"  (other band):        {other}")
    for k in dec_order:
        lst = by_dec.get(k) or []
        if not lst:
            continue
        print(f"  mean score when decision={k} :  {sum(lst)/len(lst):.2f}  (n={len(lst)})")


for line in lines:
    pm = pref_re.search(line)
    fm = fs_re.search(line)
    if not pm and not fm:
        continue
    ts = None
    lm = leader_re.match(line)
    if lm:
        ts = parse_leader_ts(lm.group(1))
    if ts is None:
        skipped_no_ts += 1
        continue
    if ts < cutoff:
        skipped_old += 1
        continue

    if pm:
        d = pm.group(1)
        pref[d] = pref[d] + 1
        sm = score_re.search(line)
        if sm and sm.group(1) != "-":
            v = int(sm.group(1))
            scores_pre.append(v)
            pref_scores[d].append(v)
        else:
            miss_score_pre += 1
        hp = prompt_re.search(line)
        hc = completion_re.search(line)
        if hp or hc:
            lines_tok_pre += 1
            sum_p_pre += int(hp.group(1)) if hp else 0
            sum_c_pre += int(hc.group(1)) if hc else 0
        else:
            lines_miss_pre += 1

    if fm:
        d = fm.group(1)
        fs[d] = fs[d] + 1
        sm = score_re.search(line)
        if sm and sm.group(1) != "-":
            v = int(sm.group(1))
            scores_fs.append(v)
            fs_scores[d].append(v)
        else:
            miss_score_fs += 1
        hp = prompt_re.search(line)
        hc = completion_re.search(line)
        if hp or hc:
            lines_tok_fs += 1
            sum_p_fs += int(hp.group(1)) if hp else 0
            sum_c_fs += int(hc.group(1)) if hc else 0
        else:
            lines_miss_fs += 1

pref_total = pref["proceed"] + pref["drop"] + pref["keep"] + pref["error"]
fs_total = fs["proceed"] + fs["keep"] + fs["drop"]
pct = (100.0 * pref["drop"] / pref_total) if pref_total else None

print()
print(f"ContentEnhancement log window: last {hours} hour(s), cutoff {cutoff.strftime('%Y-%m-%d %H:%M:%S')} UTC")
print()
print("=== Prefilter (title + RSS; one line per item when prefilter is enabled) ===")
print(f"  proceed:  {pref['proceed']}   (score OK -> fetch / full pipeline)")
print(f"  drop:     {pref['drop']}   (below threshold + discard; not inserted)")
print(f"  keep:     {pref['keep']}   (below threshold but kept raw; skip full enhance)")
print(f"  error:    {pref['error']}   (LLM failed; falls back to full pipeline)")
print(f"  Total:    {pref_total}")
if pct is not None:
    print(f"  Dropped at prefilter (drop / total):  {pct:.2f}%")
else:
    print("  Dropped at prefilter (drop / total):  n/a (no prefilter lines in window)")
print(f"  Prefilter prompt tokens (sum):   {sum_p_pre}")
print(f"  Prefilter completion tokens (sum): {sum_c_pre}")
print_score_stats(
    "Relevance score (prefilter)",
    scores_pre,
    miss_score_pre,
    pref_scores,
    ("proceed", "drop", "keep", "error"),
)
print()
print("=== Full scan (after fetch; proceed|keep|drop) ===")
print(f"  proceed:  {fs['proceed']}")
print(f"  keep:     {fs['keep']}")
print(f"  drop:     {fs['drop']}")
print(f"  Total:    {fs_total}")
print(f"  Fullscan prompt tokens (sum):    {sum_p_fs}")
print(f"  Fullscan completion tokens (sum): {sum_c_fs}")
print(f"  Lines with token fields:     {lines_tok_fs}")
print(f"  Lines without token fields:    {lines_miss_fs}")
print_score_stats(
    "Relevance score (fullscan)",
    scores_fs,
    miss_score_fs,
    fs_scores,
    ("proceed", "keep", "drop"),
)
print()
print(f"Skipped (older than window): {skipped_old}")
print(f"Skipped (no timestamp):      {skipped_no_ts}")
PY

docker exec "$CONTAINER" sh -c 'find /var/www/FreshRSS/data/users -name log.txt -exec cat {} \;' | python3 "$TMPPY"
