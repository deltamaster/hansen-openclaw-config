# Upload memes.json and meme\*.gif to the static HTTPS docroot on the gateway VM.
# Requires: OpenSSH scp, and (for HTTPS URLs) nginx + cert already working on the host.
#
# Usage (from repo root or any directory):
#   .\scripts\sync-static-memes.ps1

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Key = Join-Path $RepoRoot "openclaw-hetzner-ed25519"
$Target = "openclaw@178.104.115.113:/var/www/meme-static/"
if (-not (Test-Path $Key)) {
    Write-Error "SSH key not found: $Key"
}
$memesJson = Join-Path $RepoRoot "memes.json"
$memeDir = Join-Path $RepoRoot "meme"
if (-not (Test-Path $memesJson)) {
    Write-Error "memes.json not found under $RepoRoot"
}
& scp -i $Key $memesJson $Target
if (Test-Path $memeDir) {
    & scp -i $Key -r $memeDir "openclaw@178.104.115.113:/var/www/meme-static/"
}
& ssh -i $Key openclaw@178.104.115.113 "sudo chmod -R a+rX /var/www/meme-static"
Write-Host "Synced to $Target"
Write-Host "Example URL: https://de.hansenh.xyz/meme/getting-off-work.gif"
