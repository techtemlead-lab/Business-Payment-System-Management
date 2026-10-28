@echo off
setlocal
cd /d "%~dp0\.."
echo Starting Business Payment System Auto-Sync Monitor...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0auto-sync.ps1" %*
endlocal
