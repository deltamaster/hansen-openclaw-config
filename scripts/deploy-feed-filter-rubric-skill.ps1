# Deploy feed-filter-rubric-prompt skill to the OpenClaw gateway workspace on Hetzner.
# Remote path: ~/.openclaw/workspace/skills/feed-filter-rubric-prompt/
# Removes legacy path freshrss-contentenhancement-scoring if present.
# Requires: OpenSSH ssh/scp; key openclaw-hetzner-ed25519 in repo root.

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Key = Join-Path $RepoRoot "openclaw-hetzner-ed25519"
$Skill = Join-Path $RepoRoot "skills\feed-filter-rubric-prompt"
$HostUser = "openclaw@178.104.115.113"
$RemoteSkill = "~/.openclaw/workspace/skills/feed-filter-rubric-prompt"
$LegacySkill = "~/.openclaw/workspace/skills/freshrss-contentenhancement-scoring"

if (-not (Test-Path $Key)) { throw "Missing key: $Key" }
if (-not (Test-Path $Skill)) { throw "Missing skill directory: $Skill" }

Write-Host "Removing legacy remote skill dir (if any) ..."
ssh -i $Key -o BatchMode=yes $HostUser "rm -rf $LegacySkill"

Write-Host "Removing remote $RemoteSkill (if any) ..."
ssh -i $Key -o BatchMode=yes $HostUser "mkdir -p ~/.openclaw/workspace/skills && rm -rf $RemoteSkill"

Write-Host "Uploading skill to ${HostUser}:~/.openclaw/workspace/skills/ ..."
& scp -i $Key -r $Skill "${HostUser}:~/.openclaw/workspace/skills/"

Write-Host "Done. Skill path on gateway: $RemoteSkill"
Write-Host "Optional: ssh ... 'systemctl --user restart openclaw-gateway.service'"
