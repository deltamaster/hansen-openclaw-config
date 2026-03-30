# Fetch FreshRSS logs (Content Enhancement "ok" lines) and aggregate counts / LLM token sums for a time window.
# Default: SSH to Hetzner and read log.txt files inside the freshrss container (same host/key as deploy-freshrss-extension-hetzner.ps1).
#
# Log format (FreshRSS): [YYYY-MM-DD HH:MM:SS] or RFC 2822 [Mon, 30 Mar 2026 07:40:10 +0800], then [warning] ...
#   ContentEnhancement: fullscan (proceed|keep|drop) title="..." link=... relevance_score=... llm_tokens prompt=... completion=... total=...
# Usage:
#   .\check-freshrss-contentenhancement-log.ps1
#   .\check-freshrss-contentenhancement-log.ps1 -Hours 12
#   .\check-freshrss-contentenhancement-log.ps1 -LocalLogPath I:\path\to\log.txt
#   .\check-freshrss-contentenhancement-log.ps1 -ShowMatchingLines
#   .\check-freshrss-contentenhancement-log.ps1 -LogTimestampsUtc   # if FreshRSS logs UTC (typical on Linux VPS)

[CmdletBinding()]
param(
    [Parameter()]
    [double] $Hours = 24,

    [Parameter()]
    [string] $LocalLogPath,

    [Parameter()]
    [string] $HostUser = "openclaw@178.104.115.113",

    [Parameter()]
    [string] $Container = "freshrss",

    [Parameter()]
    [switch] $ShowMatchingLines,

    [Parameter()]
    [switch] $IncludeUndated,

    [Parameter(HelpMessage = "Treat bracket timestamps as UTC (compare to UtcNow). Use for remote server logs in UTC.")]
    [switch] $LogTimestampsUtc
)

$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Key = Join-Path $RepoRoot "openclaw-hetzner-ed25519"

$leaderPattern = '^\[(?<leader>[^\]]+)\]'
# Full-pass success lines (structured; see Processor::formatContentEnhancementLogLine).
$okPattern = 'ContentEnhancement:\s+fullscan\s+(proceed|keep|drop)\s'

function ConvertTo-LogUtcTime([string] $leader) {
    if ([string]::IsNullOrWhiteSpace($leader)) { return $null }
    try {
        $dto = [DateTimeOffset]::Parse($leader, [Globalization.CultureInfo]::GetCultureInfo('en-US'))
        return $dto.UtcDateTime
    } catch {
        try {
            $dt = [DateTime]::ParseExact($leader, 'yyyy-MM-dd HH:mm:ss', [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::None)
            if ($LogTimestampsUtc) {
                return [DateTime]::SpecifyKind($dt, [DateTimeKind]::Utc)
            }
            return $dt
        } catch {
            return $null
        }
    }
}

function Get-LogText {
    if ($LocalLogPath) {
        if (-not (Test-Path -LiteralPath $LocalLogPath)) {
            throw "LocalLogPath not found: $LocalLogPath"
        }
        return Get-Content -LiteralPath $LocalLogPath -Encoding UTF8 -Raw
    }

    if (-not (Test-Path -LiteralPath $Key)) {
        throw "Missing SSH key: $Key (use -LocalLogPath to analyze a file offline)"
    }

    $remote = "docker exec $Container sh -c 'find /var/www/FreshRSS/data/users -name log.txt -exec cat {} \;'"
    $out = & ssh -i $Key -o BatchMode=yes $HostUser $remote 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "ssh failed ($LASTEXITCODE): $out"
    }
    return ($out | Out-String)
}

# Window end: compare log times in UTC (RFC 2822 logs include offsets; naive ISO is interpreted per -LogTimestampsUtc).
if ($LogTimestampsUtc) {
    $cutoff = [DateTime]::UtcNow.AddHours(-$Hours)
} else {
    $cutoff = (Get-Date).ToUniversalTime().AddHours(-$Hours)
}
$raw = Get-LogText
$lines = $raw -split "`r?`n"

$matched = 0
$skippedNoTs = 0
$skippedOld = 0
$sumPrompt = [long]0
$sumCompletion = [long]0
$linesWithTokens = 0
$linesMissingTokens = 0

foreach ($line in $lines) {
    if ($line -notmatch $okPattern) { continue }

    $ts = $null
    if ($line -match $leaderPattern) {
        $ts = ConvertTo-LogUtcTime $Matches['leader']
    }

    if ($null -eq $ts) {
        if (-not $IncludeUndated) {
            $skippedNoTs++
            continue
        }
        # Undated lines: include in counts; cannot apply time window
    } elseif ($ts.ToUniversalTime() -lt $cutoff) {
        $skippedOld++
        continue
    }

    $matched++
    if ($ShowMatchingLines) {
        Write-Host $line
    }

    $p = 0
    $c = 0
    $hasP = $line -match '\bprompt=(\d+)'
    if ($hasP) { $p = [long]$Matches[1] }
    $hasC = $line -match '\bcompletion=(\d+)'
    if ($hasC) { $c = [long]$Matches[1] }

    if ($hasP -or $hasC) {
        $linesWithTokens++
        $sumPrompt += $p
        $sumCompletion += $c
    } else {
        $linesMissingTokens++
    }
}

Write-Host ""
$cutoffLabel = "cutoff $($cutoff.ToString('yyyy-MM-dd HH:mm:ss')) UTC"
Write-Host "ContentEnhancement: fullscan proceed|keep|drop lines (last $Hours hour(s), $cutoffLabel)"
Write-Host "  Matching lines in window:     $matched"
Write-Host "  Sum prompt tokens:           $sumPrompt"
Write-Host "  Sum completion tokens:       $sumCompletion"
Write-Host "  Lines with token fields:     $linesWithTokens"
Write-Host "  Lines without token fields:    $linesMissingTokens"
Write-Host "  Skipped (older than window): $skippedOld"
if (-not $IncludeUndated) {
    Write-Host "  Skipped (no timestamp):      $skippedNoTs"
}
if ($IncludeUndated) {
    Write-Host "  (IncludeUndated: undated lines counted as in-window; timestamps not applied)"
}
