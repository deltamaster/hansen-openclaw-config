# Start FreshRSS in Docker with this repo's extension bind-mounted (Windows PowerShell).
# Requires: Docker Desktop
# Usage: .\run-local.ps1
# UI: http://127.0.0.1:8081/

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "Docker is not installed or not on PATH. Install Docker Desktop: https://docs.docker.com/desktop/setup/install/windows-install/"
}

Write-Host "Pulling image (dockerproxy.net mirror)..." -ForegroundColor Cyan
docker compose pull
if ($LASTEXITCODE -ne 0) {
    Write-Warning "Pull failed. Check network / dockerproxy.net access, then run: docker compose pull && docker compose up -d"
    exit $LASTEXITCODE
}

Write-Host "Starting FreshRSS..." -ForegroundColor Cyan
docker compose up -d
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host ""
Write-Host "Open http://127.0.0.1:8081/ — complete the installer (SQLite is fine)." -ForegroundColor Green
Write-Host "Then: Administration > System extensions > enable ContentEnhancement > configure." -ForegroundColor Green
Write-Host "GFW bypass: start SOCKS on host port 1080 (e.g. ssh -D 1080) — see LOCAL-GFW-BYPASS.md" -ForegroundColor DarkGray
Write-Host "Stop: docker compose down" -ForegroundColor Gray
