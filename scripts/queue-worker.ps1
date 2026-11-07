# E-Card System - auto-restarting queue worker (Windows)
# Keeps `php artisan queue:work` alive; restarts it if it crashes.
# Run at logon via Task Scheduler (see DEPLOYMENT.md).

$projectRoot = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
$logDir = Join-Path $projectRoot 'storage\logs'

if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

# ---------------------------------------------------------------------------
# The queue list is essential, not a default.
#
# Invitations are dispatched onto the named 'whatsapp' and 'sms' queues
# (InvitationCampaignService) and scheduled SMS go to 'sms'
# (ProcessScheduledSms). A worker started without --queue listens on the
# 'default' queue only, so those jobs would sit in the database forever while
# the Invitations page showed them as "pending". Keep all three.
# ---------------------------------------------------------------------------
$Queues = 'default,whatsapp,sms'

while ($true) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Path (Join-Path $logDir 'queue-worker.log') -Value "[$stamp] worker started on queues: $Queues"

    & $php (Join-Path $projectRoot 'artisan') queue:work `
        --queue=$Queues `
        --sleep=3 `
        --tries=3 `
        --backoff=30 `
        --timeout=120 `
        --max-time=3600 `
        --max-jobs=1000 `
        --memory=256 2>&1 |
        Add-Content -Path (Join-Path $logDir 'queue-worker.log')

    Add-Content -Path (Join-Path $logDir 'queue-worker.log') `
        -Value "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] worker exited; restarting in 5s"

    Start-Sleep -Seconds 5
}
