# Deploy Content Enhancement extension to FreshRSS on Hetzner (openclaw@178.104.115.113).
# Requires: OpenSSH scp/ssh; key openclaw-hetzner-ed25519 in repo root.
# Remote: container `freshrss`, path /var/www/FreshRSS/extensions/xExtension-ContentEnhancement

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Key = Join-Path $RepoRoot "openclaw-hetzner-ed25519"
$Ext = Join-Path $RepoRoot "refreshrss_content_enhancement\xExtension-ContentEnhancement"
$HostUser = "openclaw@178.104.115.113"

if (-not (Test-Path $Key)) { throw "Missing key: $Key" }
if (-not (Test-Path $Ext)) { throw "Missing extension: $Ext" }

# If /tmp/xExtension-ContentEnhancement already exists, `scp -r dir host:/tmp/xExtension-ContentEnhancement`
# nests a second copy under .../xExtension-ContentEnhancement/ and docker cp then deploys stale files.
Write-Host "Removing remote /tmp/xExtension-ContentEnhancement (if any) ..."
ssh -i $Key -o BatchMode=yes $HostUser "rm -rf /tmp/xExtension-ContentEnhancement"

Write-Host "Uploading extension to ${HostUser}:/tmp/ ..."
& scp -i $Key -r $Ext "${HostUser}:/tmp/"

# Single-line remote script avoids CRLF in here-strings breaking bash on the server.
$remoteCmd = 'docker ps --filter name=freshrss --format "{{.Names}}" | grep -q freshrss || { echo "freshrss container not running"; exit 1; }; docker cp /tmp/xExtension-ContentEnhancement/. freshrss:/var/www/FreshRSS/extensions/xExtension-ContentEnhancement/ && docker exec freshrss chown -R www-data:www-data /var/www/FreshRSS/extensions/xExtension-ContentEnhancement && echo "Deployed into container freshrss."'

Write-Host "Installing into Docker volume via docker cp ..."
ssh -i $Key -o BatchMode=yes $HostUser $remoteCmd

Write-Host "Done."
