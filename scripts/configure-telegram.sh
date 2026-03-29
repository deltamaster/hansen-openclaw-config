#!/usr/bin/env bash
# Configure Telegram on the OpenClaw Gateway host (see OPENCLAW-DEPLOYMENT.md).
# Docs: https://docs.openclaw.ai/channels/telegram
set -euo pipefail

TOKEN="${1:-${TELEGRAM_BOT_TOKEN:-}}"
if [[ -z "${TOKEN}" ]]; then
  echo "Usage: $0 <bot_token>"
  echo "   or: TELEGRAM_BOT_TOKEN=<token> $0"
  echo ""
  echo "Create a bot with @BotFather (/newbot), copy the token, then run this script as the openclaw user on the Gateway host."
  exit 1
fi

openclaw channels add --channel telegram --token "${TOKEN}"

# Quick-setup defaults from https://docs.openclaw.ai/channels/telegram
openclaw config set channels.telegram.enabled true
openclaw config set channels.telegram.dmPolicy pairing
# Wildcard group defaults (docs quick setup): require @mention in groups
printf '%s\n' '{"*":{"requireMention":true}}' > /tmp/openclaw-telegram-groups.json
if openclaw config set channels.telegram.groups "$(cat /tmp/openclaw-telegram-groups.json)" --strict-json; then
  rm -f /tmp/openclaw-telegram-groups.json
else
  echo "Note: set channels.telegram.groups in Control UI Raw JSON if the above failed."
fi

systemctl --user restart openclaw-gateway.service

echo ""
echo "Telegram channel added. Next (from docs):"
echo "  1) DM your bot from Telegram."
echo "  2) On the server: openclaw pairing list telegram"
echo "  3) openclaw pairing approve telegram <CODE>"
echo ""
echo "Optional env instead of config token: TELEGRAM_BOT_TOKEN (default account only). See docs."
