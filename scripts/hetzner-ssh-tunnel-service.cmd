@echo off
REM Wrapper for Windows Service (sc.exe) — working directory = script folder.
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0hetzner-ssh-tunnel.ps1"
