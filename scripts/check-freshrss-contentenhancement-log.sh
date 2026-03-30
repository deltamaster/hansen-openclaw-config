#!/usr/bin/env bash
# Run on the FreshRSS host (same logic as check-freshrss-contentenhancement-log.ps1).
# Reads log.txt files from the freshrss container and aggregates ContentEnhancement fullscan proceed|keep|drop lines.
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
ok_re = re.compile(r"ContentEnhancement:\s+fullscan\s+(proceed|keep|drop)\s")
prompt_re = re.compile(r"\bprompt=(\d+)")
completion_re = re.compile(r"\bcompletion=(\d+)")


def parse_leader_ts(s: str):
    """FreshRSS: [YYYY-MM-DD HH:MM:SS] or RFC 2822 e.g. [Mon, 30 Mar 2026 07:40:10 +0800]."""
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


matched = skipped_no_ts = skipped_old = 0
sum_prompt = sum_completion = 0
lines_with_tokens = lines_missing_tokens = 0

for line in lines:
    if not ok_re.search(line):
        continue
    ts = None
    m = leader_re.match(line)
    if m:
        ts = parse_leader_ts(m.group(1))
    if ts is None:
        skipped_no_ts += 1
        continue
    if ts < cutoff:
        skipped_old += 1
        continue
    matched += 1
    hp = prompt_re.search(line)
    hc = completion_re.search(line)
    if hp or hc:
        lines_with_tokens += 1
        sum_prompt += int(hp.group(1)) if hp else 0
        sum_completion += int(hc.group(1)) if hc else 0
    else:
        lines_missing_tokens += 1

print()
print(f"ContentEnhancement: fullscan proceed|keep|drop lines (last {hours} hour(s), cutoff {cutoff.strftime('%Y-%m-%d %H:%M:%S')} UTC)")
print(f"  Matching lines in window:     {matched}")
print(f"  Sum prompt tokens:           {sum_prompt}")
print(f"  Sum completion tokens:       {sum_completion}")
print(f"  Lines with token fields:     {lines_with_tokens}")
print(f"  Lines without token fields:    {lines_missing_tokens}")
print(f"  Skipped (older than window): {skipped_old}")
print(f"  Skipped (no timestamp):      {skipped_no_ts}")
PY

docker exec "$CONTAINER" sh -c 'find /var/www/FreshRSS/data/users -name log.txt -exec cat {} \;' | python3 "$TMPPY"
