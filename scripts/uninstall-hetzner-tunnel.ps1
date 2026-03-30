[CmdletBinding()]
param(
    [switch] $ScheduledTask,
    [switch] $WindowsService,
    [string] $TaskName = "HetznerSSH-Tunnels",
    [string] $ServiceName = "HetznerSSHTunnels"
)

$ErrorActionPreference = "Stop"

if (-not $ScheduledTask -and -not $WindowsService) {
    $ScheduledTask = $true
    $WindowsService = $true
}

if ($ScheduledTask) {
    $t = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($t) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Removed scheduled task '$TaskName'."
    } else {
        Write-Host "No scheduled task '$TaskName'."
    }
}

if ($WindowsService) {
    $svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($svc) {
        if ($svc.Status -eq "Running") { Stop-Service -Name $ServiceName -Force }
        & sc.exe delete $ServiceName | Out-Null
        Write-Host "Removed service '$ServiceName'."
    } else {
        Write-Host "No service '$ServiceName'."
    }
}
