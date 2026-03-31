# Fetch FreshRSS logs (Content Enhancement) and aggregate prefilter + fullscan lines for a time window.
# Default: SSH to Hetzner and read log.txt files inside the freshrss container (same host/key as deploy-freshrss-extension-hetzner.ps1).
#
# Log format (Processor::formatContentEnhancementLogLine):
#   ContentEnhancement: prefilter (proceed|drop|keep|error) ...
#   ContentEnhancement: fullscan (proceed|keep|drop) ...
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
$prefilterPattern = 'ContentEnhancement:\s+prefilter\s+(proceed|drop|keep|error)\s'
$fullscanPattern = 'ContentEnhancement:\s+fullscan\s+(proceed|keep|drop)\s'

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

if ($LogTimestampsUtc) {
    $cutoff = [DateTime]::UtcNow.AddHours(-$Hours)
} else {
    $cutoff = (Get-Date).ToUniversalTime().AddHours(-$Hours)
}
$raw = Get-LogText
$lines = $raw -split "`r?`n"

$pref = @{ proceed = 0; drop = 0; keep = 0; error = 0 }
$fs = @{ proceed = 0; keep = 0; drop = 0 }
$skippedNoTs = 0
$skippedOld = 0

$sumPromptFs = [long]0
$sumCompletionFs = [long]0
$linesWithTokensFs = 0
$linesMissingTokensFs = 0

$sumPromptPre = [long]0
$sumCompletionPre = [long]0
$linesWithTokensPre = 0
$linesMissingTokensPre = 0

$scoresPre = New-Object System.Collections.Generic.List[int]
$scoresFs = New-Object System.Collections.Generic.List[int]
$scoresPreNoNumeric = 0
$scoresFsNoNumeric = 0
$prefByDec = @{ proceed = (New-Object System.Collections.Generic.List[int]); drop = (New-Object System.Collections.Generic.List[int]); keep = (New-Object System.Collections.Generic.List[int]); error = (New-Object System.Collections.Generic.List[int]) }
$fsByDec = @{ proceed = (New-Object System.Collections.Generic.List[int]); keep = (New-Object System.Collections.Generic.List[int]); drop = (New-Object System.Collections.Generic.List[int]) }

$scoreRe = [regex]'\brelevance_score=(\d+|-)\b'

foreach ($line in $lines) {
    $preDecision = $null
    $fsDecision = $null
    if ($line -match $prefilterPattern) { $preDecision = $Matches[1] }
    if ($line -match $fullscanPattern) { $fsDecision = $Matches[1] }
    if ($null -eq $preDecision -and $null -eq $fsDecision) { continue }

    $ts = $null
    if ($line -match $leaderPattern) {
        $ts = ConvertTo-LogUtcTime $Matches['leader']
    }

    if ($null -eq $ts) {
        if (-not $IncludeUndated) {
            $skippedNoTs++
            continue
        }
    } elseif ($ts.ToUniversalTime() -lt $cutoff) {
        $skippedOld++
        continue
    }

    if ($ShowMatchingLines) {
        Write-Host $line
    }

    if ($null -ne $preDecision) {
        $pref[$preDecision] = $pref[$preDecision] + 1
        $sm = $scoreRe.Match($line)
        if ($sm.Success -and $sm.Groups[1].Value -ne '-') {
            $v = [int]$sm.Groups[1].Value
            [void]$scoresPre.Add($v)
            [void]$prefByDec[$preDecision].Add($v)
        } else {
            $scoresPreNoNumeric++
        }
        $hasP = $line -match '\bprompt=(\d+)'
        $p = if ($hasP) { [long]$Matches[1] } else { 0 }
        $hasC = $line -match '\bcompletion=(\d+)'
        $c = if ($hasC) { [long]$Matches[1] } else { 0 }
        if ($hasP -or $hasC) {
            $linesWithTokensPre++
            $sumPromptPre += $p
            $sumCompletionPre += $c
        } else {
            $linesMissingTokensPre++
        }
    }

    if ($null -ne $fsDecision) {
        $fs[$fsDecision] = $fs[$fsDecision] + 1
        $sm = $scoreRe.Match($line)
        if ($sm.Success -and $sm.Groups[1].Value -ne '-') {
            $v = [int]$sm.Groups[1].Value
            [void]$scoresFs.Add($v)
            [void]$fsByDec[$fsDecision].Add($v)
        } else {
            $scoresFsNoNumeric++
        }
        $hasP = $line -match '\bprompt=(\d+)'
        $p = if ($hasP) { [long]$Matches[1] } else { 0 }
        $hasC = $line -match '\bcompletion=(\d+)'
        $c = if ($hasC) { [long]$Matches[1] } else { 0 }
        if ($hasP -or $hasC) {
            $linesWithTokensFs++
            $sumPromptFs += $p
            $sumCompletionFs += $c
        } else {
            $linesMissingTokensFs++
        }
    }
}

$prefTotal = $pref['proceed'] + $pref['drop'] + $pref['keep'] + $pref['error']
$fsTotal = $fs['proceed'] + $fs['keep'] + $fs['drop']

$pctDrop = if ($prefTotal -gt 0) { [math]::Round(100.0 * $pref['drop'] / $prefTotal, 2) } else { $null }

function Write-RelevanceScoreSection {
    param(
        [string]$Title,
        [System.Collections.Generic.List[int]]$Scores,
        [int]$MissingDash,
        [hashtable]$ByDecision
    )
    Write-Host ""
    Write-Host "=== $Title ==="
    if ($null -eq $Scores -or $Scores.Count -eq 0) {
        Write-Host "  (no numeric relevance_score in window; missing/dash: $MissingDash)"
        return
    }
    $sorted = [int[]]::new($Scores.Count)
    $Scores.CopyTo($sorted)
    [array]::Sort($sorted)
    $n = $sorted.Count
    $min = $sorted[0]
    $max = $sorted[$n - 1]
    $sum = 0L
    foreach ($x in $sorted) { $sum += $x }
    $mean = [math]::Round($sum / [double]$n, 2)
    if ($n % 2 -eq 1) {
        $median = $sorted[[int][math]::Floor($n / 2)]
    } else {
        $median = [math]::Round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2.0, 2)
    }
    Write-Host "  Lines with numeric score: $n   (relevance_score=- or missing: $MissingDash)"
    Write-Host "  min / max / mean / median:  $min / $max / $mean / $median"
    $hist = @{}
    1..10 | ForEach-Object { $hist[$_] = 0 }
    foreach ($s in $sorted) {
        if ($s -ge 1 -and $s -le 10) { $hist[$s]++ }
        else { if (-not $hist.ContainsKey('other')) { $hist['other'] = 0 }; $hist['other']++ }
    }
    $histLine = (1..10 | ForEach-Object { "$($_)=$($hist[$_])" }) -join '  '
    Write-Host "  Histogram (1-10):  $histLine"
    if ($hist.ContainsKey('other') -and $hist['other'] -gt 0) {
        Write-Host "  (other band):        $($hist['other'])"
    }
    foreach ($k in ($ByDecision.Keys | Sort-Object)) {
        $lst = $ByDecision[$k]
        if ($null -eq $lst -or $lst.Count -eq 0) { continue }
        $a = 0L
        foreach ($x in $lst) { $a += $x }
        $m = [math]::Round($a / [double]$lst.Count, 2)
        Write-Host "  mean score when decision=$k :  $m  (n=$($lst.Count))"
    }
}

$cutoffLabel = "cutoff $($cutoff.ToString('yyyy-MM-dd HH:mm:ss')) UTC"
Write-Host ""
Write-Host "ContentEnhancement log window: last $Hours hour(s), $cutoffLabel"
Write-Host ""
Write-Host "=== Prefilter (title + RSS; one line per item when prefilter is enabled) ==="
Write-Host "  proceed:  $($pref['proceed'])   (score OK -> fetch / full pipeline)"
Write-Host "  drop:     $($pref['drop'])   (below threshold + discard; not inserted)"
Write-Host "  keep:     $($pref['keep'])   (below threshold but kept raw; skip full enhance)"
Write-Host "  error:    $($pref['error'])   (LLM failed; falls back to full pipeline)"
Write-Host "  Total:    $prefTotal"
if ($null -ne $pctDrop) {
    Write-Host "  Dropped at prefilter (drop / total):  $pctDrop%"
} else {
    Write-Host "  Dropped at prefilter (drop / total):  n/a (no prefilter lines in window)"
}
Write-Host "  Prefilter prompt tokens (sum):   $sumPromptPre"
Write-Host "  Prefilter completion tokens (sum): $sumCompletionPre"
Write-RelevanceScoreSection -Title "Relevance score (prefilter)" -Scores $scoresPre -MissingDash $scoresPreNoNumeric -ByDecision $prefByDec
Write-Host ""
Write-Host "=== Full scan (after fetch; proceed|keep|drop) ==="
Write-Host "  proceed:  $($fs['proceed'])"
Write-Host "  keep:     $($fs['keep'])"
Write-Host "  drop:     $($fs['drop'])"
Write-Host "  Total:    $fsTotal"
Write-Host "  Fullscan prompt tokens (sum):    $sumPromptFs"
Write-Host "  Fullscan completion tokens (sum): $sumCompletionFs"
Write-Host "  Lines with token fields:     $linesWithTokensFs"
Write-Host "  Lines without token fields:    $linesMissingTokensFs"
Write-RelevanceScoreSection -Title "Relevance score (fullscan)" -Scores $scoresFs -MissingDash $scoresFsNoNumeric -ByDecision $fsByDec
Write-Host ""
Write-Host "Skipped (older than window): $skippedOld"
if (-not $IncludeUndated) {
    Write-Host "Skipped (no timestamp):      $skippedNoTs"
}
if ($IncludeUndated) {
    Write-Host "(IncludeUndated: undated lines counted as in-window; timestamps not applied)"
}
