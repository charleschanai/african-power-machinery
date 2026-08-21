# E-Card System - MySQL database backup script (Windows)
# Usage: powershell -ExecutionPolicy Bypass -File scripts\backup-db.ps1
# Recommended: run daily via Task Scheduler (see DEPLOYMENT.md)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $projectRoot '.env'
$backupDir = Join-Path $projectRoot 'storage\backups'
$retentionDays = 14

if (-not (Test-Path $envFile)) {
    Write-Error ".env not found at $envFile"
    exit 1
}

function Get-EnvValue([string]$name) {
    $line = Select-String -Path $envFile -Pattern ("^" + $name + "=(.*)$") | Select-Object -First 1
    if ($null -eq $line) { return $null }
    return $line.Matches[0].Groups[1].Value.Trim().Trim('"').Trim("'")
}

$dbDatabase = Get-EnvValue 'DB_DATABASE'
$dbUser = Get-EnvValue 'DB_USERNAME'
$dbPass = Get-EnvValue 'DB_PASSWORD'
$dbHost = if ($v = Get-EnvValue 'DB_HOST') { $v } else { '127.0.0.1' }
$dbPort = if ($v = Get-EnvValue 'DB_PORT') { $v } else { '3306' }

if (-not $dbDatabase) {
    Write-Error 'DB_DATABASE is not set in .env'
    exit 1
}

if (-not (Test-Path $backupDir)) {
    New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
}

$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$file = Join-Path $backupDir "$dbDatabase`_$timestamp.sql.gz"

# Locate mysqldump (XAMPP default first)
$mysqldump = @(
    'C:\xampp\mysql\bin\mysqldump.exe',
    'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe',
    'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqldump.exe'
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $mysqldump) {
    $cmd = Get-Command mysqldump -ErrorAction SilentlyContinue
    if ($cmd) { $mysqldump = $cmd.Source }
}

if (-not $mysqldump) {
    Write-Error 'mysqldump.exe not found. Install MySQL client tools.'
    exit 1
}

$env:MYSQL_PWD = $dbPass

& $mysqldump --host=$dbHost --port=$dbPort --user=$dbUser `
    --single-transaction --routines --triggers --events `
    --no-tablespaces --column-statistics=0 `
    $dbDatabase | & 'C:\xampp\apache\bin\gzip.exe' -c > $file 2>$null

if ($LASTEXITCODE -ne 0 -or -not (Test-Path $file) -or ((Get-Item $file).Length -eq 0)) {
    # gzip fallback: plain SQL if gzip is unavailable
    $plain = $file -replace '\.sql\.gz$', '.sql'
    & $mysqldump --host=$dbHost --port=$dbPort --user=$dbUser `
        --single-transaction --routines --triggers --events `
        --no-tablespaces --column-statistics=0 `
        $dbDatabase > $plain
    if ($LASTEXITCODE -ne 0) {
        Write-Error 'Backup failed'
        exit 1
    }
    Write-Host "Backup OK: $plain"
} else {
    Write-Host "Backup OK: $file"
}

Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue

# Retention: delete backups older than N days
Get-ChildItem $backupDir -Filter "$dbDatabase`_*.sql*" |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$retentionDays) } |
    Remove-Item -Force

Write-Host 'Done.'
