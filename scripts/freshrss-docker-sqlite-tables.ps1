# Lists SQLite tables in the first FreshRSS user DB (local Docker: freshrss-local).
# Usage (from repo root): .\scripts\freshrss-docker-sqlite-tables.ps1
# Requires: Docker, container name freshrss-local

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path $PSScriptRoot -Parent
$Script = Join-Path $RepoRoot "scripts\freshrss-list-sqlite-tables.php"
if (-not (Test-Path $Script)) {
	Write-Error "Missing $Script"
}
docker cp $Script "freshrss-local:/tmp/freshrss-list-sqlite-tables.php"
docker exec freshrss-local php /tmp/freshrss-list-sqlite-tables.php
