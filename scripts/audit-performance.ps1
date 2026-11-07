# E-Card System - database and performance audit (Windows)
#
# Reports what the monitoring layer cannot: which indexes exist, which ones
# are unused, and which tables are growing without bound.
#
# Run manually, or schedule it weekly. It is read-only.

$projectRoot = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'

if (-not (Test-Path $php)) {
    $php = (Get-Command php -ErrorAction SilentlyContinue).Source
}

if (-not $php) {
    Write-Error 'php.exe not found. Set the path at the top of this script.'
    exit 1
}

$artisan = Join-Path $projectRoot 'artisan'

Write-Host ''
Write-Host '=== Slow queries recorded in the last 24h ===' -ForegroundColor Cyan
& $php $artisan monitor:health --json 2>&1 | Out-String | Write-Host

Write-Host '=== Logs (storage/logs) ===' -ForegroundColor Cyan
$logDir = Join-Path $projectRoot 'storage\logs'
if (Test-Path $logDir) {
    Get-ChildItem $logDir -File |
        Sort-Object Length -Descending |
        Select-Object -First 10 Name, @{n='MB';e={[math]::Round($_.Length/1MB, 2)}}, LastWriteTime |
        Format-Table -AutoSize
} else {
    Write-Host 'No log directory yet.'
}

Write-Host ''
Write-Host '=== Failed queue jobs ===' -ForegroundColor Cyan
& $php $artisan queue:failed 2>&1 | Select-Object -First 20 | Out-String | Write-Host

Write-Host ''
Write-Host '=== Queue backlog (a worker must listen on all three) ===' -ForegroundColor Cyan
Write-Host 'default, whatsapp, sms'
& $php $artisan tinker --execute='print_r(Illuminate\Support\Facades\DB::table("jobs")->select("queue", Illuminate\Support\Facades\DB::raw("count(*) as total"))->groupBy("queue")->pluck("total","queue")->toArray());' 2>&1 | Out-String | Write-Host

Write-Host ''
Write-Host '=== Data retention ===' -ForegroundColor Cyan
& $php $artisan data:prune --dry-run 2>&1 | Out-String | Write-Host
