#!/usr/bin/env bash
# One-off: set plugins.allow to trust openclaw-weixin (run as openclaw on gateway).
set -euo pipefail
printf '%s\n' '["openclaw-weixin"]' > /tmp/plugins-allow.json
openclaw config set plugins.allow "$(cat /tmp/plugins-allow.json)" --strict-json
rm -f /tmp/plugins-allow.json
openclaw config get plugins.allow
