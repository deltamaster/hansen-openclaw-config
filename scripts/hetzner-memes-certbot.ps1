# Run certbot on the gateway after TCP 80 (and 443) are allowed in Hetzner Cloud Firewall.
# Requires: openclaw-hetzner-ed25519 in repo root.

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Key = Join-Path $RepoRoot "openclaw-hetzner-ed25519"
$remote = @'
sudo certbot --nginx -d de.hansenh.xyz --non-interactive --agree-tos --register-unsafely-without-email --redirect && sudo systemctl reload nginx && echo CERTBOT_OK
'@
& ssh -i $Key -o BatchMode=yes openclaw@178.104.115.113 $remote
