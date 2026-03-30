# Persistent SSH tunnel: reconnects when the server drops idle sessions or the link fails.
# Default: local 8080 -> remote OpenClaw gateway (18789), local 8081 -> remote FreshRSS (8080).
# Config: hetzner-ssh-tunnel.config.json next to this script (KeyPath empty = repo openclaw-hetzner-ed25519).

[CmdletBinding()]
param(
    [string] $ConfigPath = ""
)

$ErrorActionPreference = "Stop"
$ScriptDir = $PSScriptRoot
if (-not $ConfigPath) {
    $ConfigPath = Join-Path $ScriptDir "hetzner-ssh-tunnel.config.json"
}

function Write-Log {
    param([string] $Message)
    $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $Message"
    if ($script:LogFile) {
        Add-Content -LiteralPath $script:LogFile -Value $line -Encoding utf8
    }
    Write-Host $line
}

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    throw "Missing config: $ConfigPath"
}

$raw = Get-Content -LiteralPath $ConfigPath -Raw -Encoding utf8 | ConvertFrom-Json
$repoRoot = Split-Path -Parent $ScriptDir
$defaultKey = Join-Path $repoRoot "openclaw-hetzner-ed25519"
$keyPath = if ([string]::IsNullOrWhiteSpace($raw.KeyPath)) { $defaultKey } else { $raw.KeyPath }
if (-not (Test-Path -LiteralPath $keyPath)) {
    throw "SSH key not found: $keyPath"
}

$sshExe = if ([string]::IsNullOrWhiteSpace($raw.SSHExecutable)) { "ssh" } else { $raw.SSHExecutable }
function Get-IntOrDefault {
    param($Value, [int] $Default)
    if ($null -eq $Value) { return $Default }
    return [int]$Value
}
$alive = Get-IntOrDefault $raw.ServerAliveIntervalSeconds 30
$aliveMax = Get-IntOrDefault $raw.ServerAliveCountMax 3
$delay0 = Get-IntOrDefault $raw.ReconnectInitialDelaySeconds 5
$delayMax = Get-IntOrDefault $raw.ReconnectMaxDelaySeconds 120
$script:LogFile = $null
if ($raw.LogPath -and -not [string]::IsNullOrWhiteSpace([string]$raw.LogPath)) {
    $script:LogFile = [Environment]::ExpandEnvironmentVariables([string]$raw.LogPath)
} else {
    $script:LogFile = Join-Path $env:LOCALAPPDATA "hetzner-env-ssh-tunnel.log"
}

$hostUser = [string]$raw.SSHHost
$forwards = @($raw.Forwards)
if (-not $hostUser -or $forwards.Count -eq 0) {
    throw "Config must set SSHHost and at least one forward in Forwards."
}

$forwardArgs = [System.Collections.Generic.List[string]]::new()
foreach ($f in $forwards) {
    $lp = [int]$f.LocalPort
    $rh = [string]$f.RemoteHost
    if ([string]::IsNullOrWhiteSpace($rh)) { $rh = "127.0.0.1" }
    $rp = [int]$f.RemotePort
    $forwardArgs.Add("-L")
    $forwardArgs.Add("${lp}:${rh}:${rp}")
}

Write-Log "Starting tunnel manager -> $hostUser ($($forwardArgs.Count / 2) forwards). Log: $script:LogFile"

$reconnectDelay = $delay0
while ($true) {
    $argList = [System.Collections.Generic.List[string]]::new()
    $argList.Add("-N")
    $argList.Add("-i")
    $argList.Add($keyPath)
    $argList.Add("-oBatchMode=yes")
    $argList.Add("-oServerAliveInterval=$alive")
    $argList.Add("-oServerAliveCountMax=$aliveMax")
    $argList.Add("-oTCPKeepAlive=yes")
    $argList.Add("-oExitOnForwardFailure=yes")
    $argList.Add("-oStrictHostKeyChecking=accept-new")
    foreach ($x in $forwardArgs) { $argList.Add($x) }
    $argList.Add($hostUser)

    Write-Log "Connecting ssh (reconnect delay was ${reconnectDelay}s)..."
    $started = Get-Date
    $p = Start-Process -FilePath $sshExe -ArgumentList @($argList.ToArray()) -NoNewWindow -PassThru -Wait
    $code = $p.ExitCode
    $ranSec = ((Get-Date) - $started).TotalSeconds
    Write-Log "ssh exited with code $code after $([int]$ranSec)s. Will retry."

    Start-Sleep -Seconds $reconnectDelay
    # Clean disconnect (e.g. idle timeout) usually exits 0 — next attempt after a short wait.
    if ($code -eq 0) {
        $reconnectDelay = $delay0
    } else {
        $reconnectDelay = [Math]::Min([Math]::Max($delay0, [int]($reconnectDelay * 1.5)), $delayMax)
    }
}
