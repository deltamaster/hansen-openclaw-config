#!/usr/bin/env bash
# Back-compat entry point: forwards to the skill-local implementation.
set -euo pipefail

_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec bash "${_REPO_ROOT}/skills/daily-news-aggregation/scripts/send-daily-news-email.sh" "$@"
