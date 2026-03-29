#!/usr/bin/env bash
# Install nginx + Let's Encrypt HTTPS for static meme files on the gateway VM.
# Run on the server with sudo (openclaw user has passwordless sudo).
#
# Prerequisites:
#   - DNS A record de.hansenh.xyz -> this host's public IPv4
#   - Hetzner Cloud Firewall: allow TCP 80 and 443 from the internet
#     (Let's Encrypt HTTP-01 hits http://<domain>/.well-known/ on **port 80**; 443 alone is not enough.)
#
# Usage (HTTPS + certbot):
#   export CERTBOT_EMAIL='you@example.com'
#   sudo -E bash scripts/install-static-memes-https.sh
#
# HTTP only (no certbot — use for testing; add TLS later with same script without --http-only):
#   sudo bash scripts/install-static-memes-https.sh --http-only
#
# After install, sync files from your workstation:
#   scp -i openclaw-hetzner-ed25519 memes.json openclaw@178.104.115.113:/var/www/meme-static/
#   scp -i openclaw-hetzner-ed25519 -r meme openclaw@178.104.115.113:/var/www/meme-static/

set -euo pipefail

HTTP_ONLY=false
if [[ "${1:-}" == "--http-only" ]]; then
  HTTP_ONLY=true
fi

if [[ "${EUID:-0}" -ne 0 ]]; then
  echo "Run with sudo: sudo bash $0" >&2
  exit 1
fi

if [[ "${HTTP_ONLY}" != true && -z "${CERTBOT_EMAIL:-}" ]]; then
  echo "Set CERTBOT_EMAIL for Let's Encrypt expiry notices, e.g.:" >&2
  echo "  export CERTBOT_EMAIL='you@example.com'" >&2
  echo "  sudo -E bash $0" >&2
  echo "Or use: sudo bash $0 --http-only" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
NGINX_SRC="${REPO_ROOT}/config/nginx/de.hansenh.xyz-static-memes.conf"
SITE_NAME="de.hansenh.xyz-static-memes"
DOCROOT="/var/www/meme-static"

if [[ ! -f "${NGINX_SRC}" ]]; then
  echo "Missing nginx config: ${NGINX_SRC}" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx certbot python3-certbot-nginx

install -d -m 2775 -o openclaw -g www-data "${DOCROOT}/meme"
# nginx only needs read; openclaw deploys via scp (group-writable tree)

if [[ -f /etc/nginx/sites-enabled/default ]]; then
  rm -f /etc/nginx/sites-enabled/default
fi

install -m 0644 "${NGINX_SRC}" "/etc/nginx/sites-available/${SITE_NAME}.conf"
ln -sf "/etc/nginx/sites-available/${SITE_NAME}.conf" "/etc/nginx/sites-enabled/${SITE_NAME}.conf"

nginx -t
systemctl enable nginx
systemctl reload nginx

if [[ "${HTTP_ONLY}" == true ]]; then
  echo ""
  echo "OK (HTTP only): http://de.hansenh.xyz/meme/"
  echo "Upload GIFs under ${DOCROOT}/meme/ and memes.json to ${DOCROOT}/"
  echo "Run again with CERTBOT_EMAIL set (no --http-only) to enable HTTPS."
  exit 0
fi

# Obtain/renew cert and install nginx SSL server block
certbot --nginx \
  -d de.hansenh.xyz \
  --non-interactive \
  --agree-tos \
  -m "${CERTBOT_EMAIL}" \
  --redirect

systemctl reload nginx

echo ""
echo "OK: https://de.hansenh.xyz/meme/ (upload GIFs under ${DOCROOT}/meme/)"
echo "    https://de.hansenh.xyz/memes.json"
echo "Example media URL for Telegram: https://de.hansenh.xyz/meme/getting-off-work.gif"
