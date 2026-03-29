# Quick checks from your PC: HTTP (80) and HTTPS (443) for the static meme host.
# Let's Encrypt needs port 80 reachable from the internet; open TCP 80 in Hetzner Cloud Firewall.

$ErrorActionPreference = "Continue"
$hostName = "de.hansenh.xyz"
Write-Host "=== $hostName ===" -ForegroundColor Cyan
foreach ($url in @(
        "http://${hostName}/memes.json",
        "https://${hostName}/memes.json"
    )) {
    Write-Host "`nGET $url" -ForegroundColor Yellow
    try {
        $r = Invoke-WebRequest -Uri $url -Method Head -TimeoutSec 15 -UseBasicParsing
        Write-Host "  Status: $($r.StatusCode)" -ForegroundColor Green
    }
    catch {
        Write-Host "  FAIL: $($_.Exception.Message)" -ForegroundColor Red
    }
}
