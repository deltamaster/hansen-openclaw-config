#!/usr/bin/env bash
# Patch global OpenClaw: strip truncated </final fragments for Telegram HTML mode.
# Run on the gateway host as a user with sudo (see OPENCLAW-DEPLOYMENT.md).
set -euo pipefail

ROOT="/usr/lib/node_modules/openclaw/dist"
TR="${ROOT}/text-runtime-B-kOpuLv.js"
PI="${ROOT}/pi-embedded-BaSvmUpW.js"

for f in "$TR" "$PI"; do
  [[ -f "$f" ]] || { echo "missing: $f" >&2; exit 1; }
done

sudo cp -a "$TR" "${TR}.bak-telegram-final"
sudo cp -a "$PI" "${PI}.bak-telegram-final"

sudo python3 <<'PY'
from pathlib import Path

tr = Path("/usr/lib/node_modules/openclaw/dist/text-runtime-B-kOpuLv.js")
text = tr.read_text(encoding="utf-8")
needle = "\t} else FINAL_TAG_RE.lastIndex = 0;\n\tconst codeRegions = findCodeRegions(cleaned);"
insert = (
    "\t} else FINAL_TAG_RE.lastIndex = 0;\n"
    "\tcleaned = cleaned.replace(/<\\s*\\/?\\s*final\\b[^<>]*$/gi, \"\");\n"
    "\tconst codeRegions = findCodeRegions(cleaned);"
)
if insert in text:
    print("text-runtime: already patched")
elif needle in text:
    text = text.replace(needle, insert, 1)
    tr.write_text(text, encoding="utf-8")
    print("text-runtime: patched")
else:
    raise SystemExit("text-runtime: expected snippet not found; openclaw upgrade?")

pi = Path("/usr/lib/node_modules/openclaw/dist/pi-embedded-BaSvmUpW.js")
pt = pi.read_text(encoding="utf-8")
old = '''const REASONING_TAG_PREFIXES = [
	"<think",
	"<thinking",
	"<thought",
	"<antthinking",
	"</think",
	"</thinking",
	"</thought",
	"</antthinking"
];'''
new = '''const REASONING_TAG_PREFIXES = [
	"<think",
	"<thinking",
	"<thought",
	"<antthinking",
	"</think",
	"</thinking",
	"</thought",
	"</antthinking",
	"<final",
	"</final"
];'''
if new in pt:
    print("pi-embedded: already patched")
elif old in pt:
    pt = pt.replace(old, new, 1)
    pi.write_text(pt, encoding="utf-8")
    print("pi-embedded: patched")
else:
    raise SystemExit("pi-embedded: expected REASONING_TAG_PREFIXES block not found; openclaw upgrade?")
PY

echo "Restarting gateway..."
systemctl --user restart openclaw-gateway.service
systemctl --user is-active openclaw-gateway.service
