#!/usr/bin/env bash
# Non-interactive prep for MiniMax Token Plan (OAuth) on the OpenClaw gateway.
# Full flow: OPENCLAW-PATCHES-AND-CONFIG.md §1.3
# Platform: https://platform.minimax.io/docs/token-plan/openclaw
set -euo pipefail

openclaw plugins enable minimax

if systemctl --user is-active --quiet openclaw-gateway.service 2>/dev/null; then
  systemctl --user restart openclaw-gateway.service
else
  openclaw gateway restart || true
fi

echo ""
echo "MiniMax plugin enabled and gateway restarted (if systemd unit was active)."
echo "Next — add MiniMax only (no full onboarding), on the gateway:"
echo "  openclaw configure --section model"
echo "    → pick MiniMax + CN or Global OAuth in the wizard"
echo ""
echo "Or OAuth-only via onboard (skips channels/skills/UI/health/search/daemon):"
echo "  openclaw onboard --auth-choice minimax-cn-oauth --skip-channels --skip-skills --skip-ui --skip-health --skip-search --skip-daemon"
echo "  # Global: minimax-global-oauth"
echo ""
echo "Then set default model if needed, e.g.:"
echo "  openclaw models set minimax/MiniMax-M2.7"
echo ""
