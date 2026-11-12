# E-Card System - one-command launcher (Windows)
#
# Brings the app up and opens it in the default browser. Safe to run when it is
# already running: anything already listening is left alone.
#
#   .\scripts\open-ecard.ps1              start what is missing, then open the browser
#   .\scripts\open-ecard.ps1 -NoBrowser   just make sure it is up
#   .\scripts\open-ecard.ps1 -Tunnel      also start ngrok, for HTTPS (phone camera)
#   .\scripts\open-ecard.ps1 -Production  skip Vite and serve the built assets
#
# Why -Tunnel exists: the browser only exposes navigator.mediaDevices in a
# secure context, so the QR scanner cannot work on a phone over the plain-HTTP
# LAN address. ngrok gives an https:// URL that does.

[CmdletBinding()]
param(
    [switch]$NoBrowser,
    [switch]$Tunnel,
    [switch]$Production,
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'

if (-not (Test-Path $php)) {
    $php = (Get-Command php -ErrorAction SilentlyContinue).Source
}

if (-not $php) {
    Write-Host 'PHP not found. Install it or fix the $php path in this script.' -ForegroundColor Red
    exit 1
}

function Test-Port {
    param([int]$OnPort)
    return $null -ne (Get-NetTCPConnection -State Listen -LocalPort $OnPort -ErrorAction SilentlyContinue)
}

function Wait-Http {
    param([string]$Url, [int]$Seconds = 45)
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        try {
            $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 5
            if ($r.StatusCode -lt 500) { return $true }
        } catch {
            if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -lt 500) { return $true }
        }
        Start-Sleep -Milliseconds 800
    }
    return $false
}

# ---------------------------------------------------------------------------
# 1. Frontend assets
#
# public/hot is Vite's marker for "assets come from the dev server, not from
# public/build". If it is present and Vite is not running, every page loads a
# blank screen because the bundle URLs point at a dead port. That failure looks
# like a broken app rather than a missing dev server, so it is handled here.
# ---------------------------------------------------------------------------
$hotFile = Join-Path $projectRoot 'public\hot'

if ($Production) {
    if (Test-Path $hotFile) {
        Remove-Item $hotFile -Force
        Write-Host 'Serving the built assets from public/build.' -ForegroundColor DarkGray
    }
} elseif (Test-Path $hotFile) {
    $viteUrl = (Get-Content $hotFile -Raw).Trim()
    $vitePort = ([uri]$viteUrl).Port

    if (Test-Port -OnPort $vitePort) {
        Write-Host "Vite already running on :$vitePort" -ForegroundColor DarkGray
    } else {
        Write-Host 'Starting Vite...' -ForegroundColor Cyan
        Start-Process -FilePath 'cmd.exe' `
            -ArgumentList '/c', 'npm run dev' `
            -WorkingDirectory $projectRoot `
            -WindowStyle Hidden | Out-Null

        if (Wait-Http -Url "$viteUrl/@vite/client" -Seconds 40) {
            Write-Host "Vite up on :$vitePort" -ForegroundColor Green
        } else {
            Write-Host "Vite did not come up on :$vitePort. The page may render blank." -ForegroundColor Yellow
        }
    }
} elseif (-not (Test-Path (Join-Path $projectRoot 'public\build\manifest.json'))) {
    Write-Host 'No public/build and no dev server. Building assets first...' -ForegroundColor Cyan
    Push-Location $projectRoot
    & npm.cmd run build
    Pop-Location
}

# ---------------------------------------------------------------------------
# 2. The application server
# ---------------------------------------------------------------------------
if (Test-Port -OnPort $Port) {
    Write-Host "App already listening on :$Port" -ForegroundColor DarkGray
} else {
    Write-Host "Starting php artisan serve on :$Port..." -ForegroundColor Cyan
    Start-Process -FilePath $php `
        -ArgumentList @((Join-Path $projectRoot 'artisan'), 'serve', "--host=0.0.0.0", "--port=$Port") `
        -WorkingDirectory $projectRoot `
        -WindowStyle Hidden | Out-Null

    if (Wait-Http -Url "http://127.0.0.1:$Port/" -Seconds 45) {
        Write-Host "App up on :$Port" -ForegroundColor Green
    } else {
        Write-Host "App did not answer on :$Port. Run 'php artisan serve' by hand to see the error." -ForegroundColor Red
        exit 1
    }
}

# ---------------------------------------------------------------------------
# 3. Optional HTTPS tunnel
# ---------------------------------------------------------------------------
if ($Tunnel) {
    if (Get-Command ngrok -ErrorAction SilentlyContinue) {
        $global:LASTEXITCODE = 0
        & (Join-Path $PSScriptRoot 'ngrok-ecard.ps1') -Action start -Port $Port
        if ($LASTEXITCODE -ne 0) {
            Write-Host 'ngrok tunnel failed. Run .\scripts\ngrok-ecard.ps1 -Action status for details.' -ForegroundColor Yellow
        }
    } else {
        Write-Host 'ngrok not found on PATH. Install it with: winget install ngrok.ngrok' -ForegroundColor Yellow
    }
}

# ---------------------------------------------------------------------------
# 4. Open the browser
# ---------------------------------------------------------------------------
$url = "http://localhost:$Port"

Write-Host ''
Write-Host "  $url" -ForegroundColor Cyan
Write-Host ''

if (-not $NoBrowser) {
    Start-Process $url
    Write-Host 'Opened in your default browser.' -ForegroundColor Green
}
