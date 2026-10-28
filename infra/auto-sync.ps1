# Auto-sync script for Business-Payment-System-Management
# Automatically checks GitHub 'main' branch, pulls updates, runs migrations/builds, and keeps local environment synced.

param(
    [string]$Branch = "main",
    [string]$Remote = "origin",
    [int]$IntervalSeconds = 30,
    [string]$LogFile = ".local/auto-sync.log",
    [switch]$RunOnce
)

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $repoRoot

# Ensure .local directory exists for logging
$logDir = Split-Path -Parent $LogFile
if (-not [string]::IsNullOrEmpty($logDir) -and -not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

function Write-SyncLog {
    param([string]$Message, [string]$Level = "INFO")
    $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $line = "[$timestamp] [$Level] $Message"
    
    switch ($Level) {
        "ERROR"   { Write-Host $line -ForegroundColor Red }
        "WARN"    { Write-Host $line -ForegroundColor Yellow }
        "SUCCESS" { Write-Host $line -ForegroundColor Green }
        default   { Write-Host $line -ForegroundColor Cyan }
    }
    
    try {
        Add-Content -Path $LogFile -Value $line -Encoding utf8
    } catch {
        # ignore file lock write errors
    }
}

function Invoke-SyncCheck {
    try {
        # Check git status for tracked uncommitted changes
        $dirty = git status --porcelain -uno
        if ($dirty) {
            Write-SyncLog "Working directory has local modifications to tracked files. Skipping pull to avoid conflicts." "WARN"
            return
        }

        # Fetch latest changes from remote
        $fetchOutput = git fetch $Remote $Branch 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-SyncLog "git fetch failed: $fetchOutput" "ERROR"
            return
        }

        $localHash = (git rev-parse HEAD).Trim()
        $remoteHash = (git rev-parse "$Remote/$Branch").Trim()

        if ($localHash -eq $remoteHash) {
            # Everything up to date
            return
        }

        Write-SyncLog "New changes detected on $Remote/$Branch!" "INFO"
        Write-SyncLog "Local:  $localHash" "INFO"
        Write-SyncLog "Remote: $remoteHash" "INFO"

        # List changed files between current HEAD and incoming remote commits
        $changedFiles = git diff --name-only HEAD "$Remote/$Branch"

        # Pull fast-forward changes
        $pullOutput = git pull --ff-only $Remote $Branch 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-SyncLog "git pull --ff-only failed: $pullOutput" "ERROR"
            return
        }

        Write-SyncLog "Pulled latest commits successfully." "SUCCESS"

        # Check if composer dependencies changed
        if ($changedFiles -match 'composer\.(json|lock)') {
            Write-SyncLog "Composer files changed. Running composer install in container..." "INFO"
            cmd.exe /c "docker compose exec -T app composer install --no-interaction"
        }

        # Check if database migrations changed
        if ($changedFiles -match 'database/migrations/') {
            Write-SyncLog "Database migrations changed. Running migrate in container..." "INFO"
            cmd.exe /c "docker compose exec -T app php artisan migrate --force --no-interaction"
        }

        # Check if frontend assets changed
        if ($changedFiles -match 'package(-lock)?\.json|resources/|vite\.config') {
            Write-SyncLog "Frontend source/package files changed. Rebuilding frontend assets..." "INFO"
            cmd.exe /c "npm run build"
            if (Test-Path "public/hot") { Remove-Item "public/hot" -Force }
        }

        # Clear cached application configuration/routes
        cmd.exe /c "docker compose exec -T app php artisan optimize:clear" | Out-Null

        # If Docker or environment files changed, restart app container
        if ($changedFiles -match 'compose\.yaml|Dockerfile|bootstrap/|\.env') {
            Write-SyncLog "Container/runtime configuration changed. Restarting app container..." "INFO"
            cmd.exe /c "docker compose restart app"
        }

        $newHash = (git rev-parse HEAD).Trim()
        Write-SyncLog "Auto-sync complete. Application updated to $newHash." "SUCCESS"
    } catch {
        Write-SyncLog "Unexpected exception during sync: $_" "ERROR"
    }
}

Write-SyncLog "Starting auto-sync monitor on branch '$Branch' against remote '$Remote'." "INFO"
Write-SyncLog "Checking every $IntervalSeconds seconds. Press Ctrl+C to stop." "INFO"

if ($RunOnce) {
    Invoke-SyncCheck
    Write-SyncLog "Single sync check completed." "INFO"
    exit 0
}

while ($true) {
    Invoke-SyncCheck
    Start-Sleep -Seconds $IntervalSeconds
}
