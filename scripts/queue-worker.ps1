# E-Card System - auto-restarting queue worker (Windows)
# Keeps `php artisan queue:work` alive; restarts it if it crashes.
# Run at logon via Task Scheduler (see DEPLOYMENT.md).

$projectRoot = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
$logDir = Join-Path $projectRoot 'storage\logs'

if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

while ($true) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Path (Join-Path $logDir 'queue-worker.log') -Value "[$stamp] worker started"

    & $php (Join-Path $projectRoot 'artisan') queue:work `
        --sleep=3 --tries=3 --max-time=3600 --memory=256 2>&1 |
        Add-Content -Path (Join-Path $logDir 'queue-worker.log')

    Start-Sleep -Seconds 5
}
