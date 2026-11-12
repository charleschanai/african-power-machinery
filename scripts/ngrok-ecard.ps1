# E-Card System - ngrok helper (Windows)
#
# One place for the HTTPS tunnel the phone QR scanner needs (the camera API
# only works in a secure context, which the plain-HTTP LAN address is not).
#
#   .\scripts\ngrok-ecard.ps1                  start if needed, print the URL
#   .\scripts\ngrok-ecard.ps1 -Action status   is the tunnel up?
#   .\scripts\ngrok-ecard.ps1 -Action restart  kill and start again
#   .\scripts\ngrok-ecard.ps1 -Action stop     kill ngrok
#   .\scripts\ngrok-ecard.ps1 -Watch           start, then restart it if it drops
#
# Why start is idempotent: the free plan allows ONE simultaneous agent
# session. Launching a second ngrok kills the first one, which is the usual
# reason the tunnel "stops working" after running the launcher twice.

[CmdletBinding()]
param(
    [ValidateSet('start', 'stop', 'restart', 'status')]
    [string]$Action = 'start',
    [int]$Port = 8000,
    [switch]$Watch,
    [int]$WatchIntervalSeconds = 20
)

$ErrorActionPreference = 'Stop'

$logPath = Join-Path $env:TEMP 'ngrok-ecard.log'
$errPath = Join-Path $env:TEMP 'ngrok-ecard.err'
$apiUrl = 'http://127.0.0.1:4040/api/tunnels'

function Get-NgrokExe {
    $cmd = Get-Command ngrok -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    return $null
}

function Get-Tunnel {
    try {
        return (Invoke-RestMethod -Uri $apiUrl -TimeoutSec 3).tunnels
    } catch {
        return $null
    }
}

function Get-TunnelForPort {
    $tunnels = Get-Tunnel
    if (-not $tunnels) { return $null }
    return $tunnels | Where-Object { $_.config.addr -match ":$Port$" } | Select-Object -First 1
}

function Get-PublicUrl {
    $tunnel = Get-TunnelForPort
    if ($tunnel -and $tunnel.public_url) { return $tunnel.public_url }
    return $null
}

function Test-App {
    try {
        $r = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/" -UseBasicParsing -TimeoutSec 5
        return $r.StatusCode -lt 500
    } catch {
        return $false
    }
}

function Show-TunnelErrors {
    if (-not (Test-Path $logPath)) { return }
    $bad = Select-String -Path $logPath -Pattern 'lvl=error|lvl=crit|ERR_NGROK' | Select-Object -Last 5
    if ($bad) {
        Write-Host 'ngrok reported errors:' -ForegroundColor Yellow
        $bad | ForEach-Object { Write-Host "  $($_.Line)" -ForegroundColor DarkYellow }
    }
}

function Stop-Ngrok {
    $procs = Get-Process -Name ngrok -ErrorAction SilentlyContinue
    if (-not $procs) {
        Write-Host 'ngrok is not running.' -ForegroundColor DarkGray
        return
    }
    $procs | Stop-Process -Force
    Start-Sleep -Seconds 2
    Write-Host 'ngrok stopped.' -ForegroundColor Yellow
}

function Start-Tunnel {
    $exe = Get-NgrokExe
    if (-not $exe) {
        throw 'ngrok not found on PATH. Install it with: winget install ngrok.ngrok'
    }

    if (-not (Test-App)) {
        Write-Host "Nothing answers on http://127.0.0.1:$Port - start the app first." -ForegroundColor Yellow
        Write-Host '  .\scripts\open-ecard.ps1 -NoBrowser' -ForegroundColor DarkGray
    }

    $existing = Get-PublicUrl
    if ($existing) {
        Write-Host "ngrok already running: $existing" -ForegroundColor DarkGray
        return $existing
    }

    if (Get-Tunnel) {
        # Running, but for another port. Free plan allows one session, so
        # replace it instead of starting a second agent that would kill this one.
        Write-Host "ngrok is running for a different port - restarting it for :$Port" -ForegroundColor DarkGray
        Stop-Ngrok
    }

    Remove-Item $logPath, $errPath -Force -ErrorAction SilentlyContinue

    Start-Process -FilePath $exe `
        -ArgumentList @('http', "$Port", '--log', 'stdout', '--log-level', 'info') `
        -WindowStyle Hidden `
        -RedirectStandardOutput $logPath `
        -RedirectStandardError $errPath | Out-Null

    $deadline = (Get-Date).AddSeconds(30)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 800
        $url = Get-PublicUrl
        if ($url) { return $url }
    }

    Show-TunnelErrors
    throw "ngrok did not publish a URL within 30s. See $logPath"
}

function Show-Url {
    param([string]$Url)
    Write-Host ''
    Write-Host "  HTTPS:  $Url" -ForegroundColor Green
    Write-Host "  Local:  http://localhost:$Port" -ForegroundColor DarkGray
    Write-Host '  Open the HTTPS URL on your phone for the QR camera.' -ForegroundColor DarkGray
    Write-Host ''
}

try {
    switch ($Action) {
        'status' {
            $url = Get-PublicUrl
            if ($url) {
                Write-Host "ngrok is up: $url -> http://localhost:$Port" -ForegroundColor Green
            } else {
                Write-Host 'ngrok is NOT running.' -ForegroundColor Yellow
                exit 1
            }
        }
        'stop' {
            Stop-Ngrok
        }
        'restart' {
            Stop-Ngrok
            $url = Start-Tunnel
            Show-Url -Url $url
        }
        'start' {
            $url = Start-Tunnel
            Show-Url -Url $url
        }
    }
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    Show-TunnelErrors
    exit 1
}

if ($Watch) {
    Write-Host "Watching the tunnel every $WatchIntervalSeconds s (Ctrl+C to stop)..." -ForegroundColor DarkGray
    while ($true) {
        Start-Sleep -Seconds $WatchIntervalSeconds
        if (Get-PublicUrl) { continue }

        Write-Host "$(Get-Date -Format HH:mm:ss) tunnel dropped - restarting" -ForegroundColor Yellow
        try {
            Get-Process -Name ngrok -ErrorAction SilentlyContinue | Stop-Process -Force
            Start-Sleep -Seconds 2
            $url = Start-Tunnel
            Write-Host "Tunnel back: $url" -ForegroundColor Green
        } catch {
            Write-Host "Restart failed: $($_.Exception.Message)" -ForegroundColor Red
        }
    }
}

exit 0
