# Setup Windows Scheduled Task for Auto-Sync
# Usage:
#   powershell.exe -ExecutionPolicy Bypass -File infra\setup-auto-sync-task.ps1
#   powershell.exe -ExecutionPolicy Bypass -File infra\setup-auto-sync-task.ps1 -Uninstall

param(
    [switch]$Uninstall,
    [string]$TaskName = "MadinaAutoSync"
)

$scriptPath = Join-Path $PSScriptRoot "auto-sync.ps1"

if ($Uninstall) {
    Write-Host "Unregistering task '$TaskName'..." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Task '$TaskName' removed successfully." -ForegroundColor Green
    exit 0
}

Write-Host "Registering Windows Scheduled Task '$TaskName'..." -ForegroundColor Cyan

$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptPath`""
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Days 365)

try {
    # Check if task already exists and remove old version
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Description "Auto-syncs Madina repository main branch from GitHub" | Out-Null
    Write-Host "Scheduled task '$TaskName' registered successfully!" -ForegroundColor Green
    Write-Host "The sync process will start automatically when you log in, or you can start it now with:" -ForegroundColor White
    Write-Host "  Start-ScheduledTask -TaskName '$TaskName'" -ForegroundColor Yellow
} catch {
    Write-Host "Failed to register scheduled task: $_" -ForegroundColor Red
    Write-Host "Note: Administrator privileges may be required to register scheduled tasks." -ForegroundColor Yellow
}
