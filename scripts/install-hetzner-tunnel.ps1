# Installs auto-start for hetzner-ssh-tunnel.ps1 (OpenClaw + FreshRSS local forwards).
# -Mode ScheduledTask: runs at user logon (recommended; uses your SSH key and drive letters).
# -Mode WindowsService: registers a real Windows service (often needs sc config ... obj= YOUR user for I: and keys).

[CmdletBinding()]
param(
    [ValidateSet("ScheduledTask", "WindowsService")]
    [string] $Mode = "ScheduledTask",
    [string] $TaskName = "HetznerSSH-Tunnels",
    [string] $ServiceName = "HetznerSSHTunnels"
)

$ErrorActionPreference = "Stop"
$ScriptDir = $PSScriptRoot
$ps1 = Join-Path $ScriptDir "hetzner-ssh-tunnel.ps1"
$cmd = Join-Path $ScriptDir "hetzner-ssh-tunnel-service.cmd"

if (-not (Test-Path -LiteralPath $ps1)) {
    throw "Missing: $ps1"
}

function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $p = New-Object Security.Principal.WindowsPrincipal($id)
    return $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if ($Mode -eq "ScheduledTask") {
    $existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($existing) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    }

    $arg = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$ps1`""
    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument $arg
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
    $trigger.Delay = "PT45S"
    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -ExecutionTimeLimit ([TimeSpan]::Zero) `
        -RestartCount 999 `
        -RestartInterval (New-TimeSpan -Minutes 1) `
        -StartWhenAvailable
    $principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive

    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
    Write-Host "Scheduled task '$TaskName' registered (At logon, 45s delay). Start now: Start-ScheduledTask -TaskName '$TaskName'"
    return
}

# WindowsService
if (-not (Test-IsAdmin)) {
    throw "WindowsService mode requires Administrator PowerShell."
}

$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($svc) {
    if ($svc.Status -eq "Running") { Stop-Service -Name $ServiceName -Force }
    & sc.exe delete $ServiceName | Out-Null
    Start-Sleep -Seconds 2
}

# sc.exe expects: binPath= "<exe and args>" (space after =). Quote paths with spaces.
$binPathValue = "C:\Windows\System32\cmd.exe /c `"$cmd`""
$scArgs = @(
    "create", $ServiceName,
    "binPath= $binPathValue",
    "start= auto",
    'DisplayName= "Hetzner SSH tunnels (OpenClaw + FreshRSS)"'
)
& sc.exe @scArgs | Out-String | Write-Host
if ($LASTEXITCODE -ne 0) {
    throw "sc.exe create failed (exit $LASTEXITCODE)."
}

Write-Host @"
Service '$ServiceName' created. It runs as Local System by default — that often cannot see your user profile or mapped drives.

If the tunnel fails to find the key or I: drive, run as your account (elevated cmd or PowerShell):

  sc config $ServiceName obj= ".\$env:USERNAME" password= YOUR_WINDOWS_PASSWORD

Then: Start-Service $ServiceName

Check logs: %LOCALAPPDATA%\hetzner-env-ssh-tunnel.log
"@
