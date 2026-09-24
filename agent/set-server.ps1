<#
    set-server.ps1
    Change l'adresse du serveur d'un agent DEJA installe, sans reinstaller.

    A utiliser quand agent.log montre des timeouts parce que config.ps1
    contient une mauvaise URL.

    Usage (PowerShell administrateur) :
        powershell -ExecutionPolicy Bypass -File .\set-server.ps1 -ServerUrl "100.10.1.136"
#>

param(
    [string]$ServerUrl,
    [string]$InstallDir = "C:\ProgramData\IntranetMonitor"
)

$ErrorActionPreference = "Stop"

$configFile = Join-Path $InstallDir "config.ps1"
if (-not (Test-Path $configFile)) {
    Write-Error "Agent non installe : $configFile introuvable."
    exit 1
}

# Affiche l'URL actuelle avant de la remplacer.
$current = (Select-String -Path $configFile -Pattern '^\$ServerUrl\s*=\s*"(.+)"' -ErrorAction SilentlyContinue).Matches.Groups[1].Value
Write-Host ""
Write-Host "  URL actuelle : $current" -ForegroundColor Yellow

if ([string]::IsNullOrWhiteSpace($ServerUrl)) {
    $ServerUrl = Read-Host "  Nouvelle adresse du serveur (ex: 100.10.1.136)"
}

# Meme normalisation que install.ps1.
$ServerUrl = $ServerUrl.Trim()
if ($ServerUrl -notmatch '^https?://') {
    $ServerUrl = "http://$ServerUrl"
}
if ($ServerUrl -notmatch 'report\.php$') {
    $ServerUrl = $ServerUrl.TrimEnd('/')
    if ($ServerUrl -notmatch '/MONITOR/server/api$') {
        if ($ServerUrl -match '/MONITOR/server$')      { $ServerUrl += '/api' }
        elseif ($ServerUrl -match '/MONITOR$')         { $ServerUrl += '/server/api' }
        else                                           { $ServerUrl += '/MONITOR/server/api' }
    }
    $ServerUrl += '/report.php'
}
$neighborsUrl = $ServerUrl -replace 'report\.php$', 'neighbors.php'

Write-Host "  Nouvelle URL : $ServerUrl" -ForegroundColor Cyan
Write-Host ""

# Test avant d'ecrire : inutile d'enregistrer une URL qui ne repond pas.
$reachable = $false
try {
    $null = Invoke-WebRequest -Uri $ServerUrl -Method Get -TimeoutSec 8 -UseBasicParsing -ErrorAction Stop
    $reachable = $true
} catch {
    $s = $null
    if ($_.Exception.Response) { try { $s = [int]$_.Exception.Response.StatusCode } catch {} }
    if ($s -eq 405 -or $s -eq 400 -or $s -eq 401) { $reachable = $true }
}

if (-not $reachable) {
    Write-Host "[ATTENTION] Le serveur ne repond pas a cette adresse." -ForegroundColor Red
    $answer = Read-Host "  Enregistrer quand meme ? (o/N)"
    if ($answer -notmatch '^[oO]') {
        Write-Host "Abandon : config.ps1 inchange." -ForegroundColor Yellow
        exit 1
    }
} else {
    Write-Host "[OK] Serveur joignable." -ForegroundColor Green
}

# Remplacement des deux lignes d'URL, le reste de la config est preserve.
$content = Get-Content $configFile -Raw
$content = $content -replace '(?m)^\$ServerUrl\s*=\s*".*"', ('$ServerUrl       = "' + $ServerUrl + '"')
$content = $content -replace '(?m)^\$NeighborsUrl\s*=\s*".*"', ('$NeighborsUrl    = "' + $neighborsUrl + '"')
Set-Content -Path $configFile -Value $content -Encoding UTF8

Write-Host "[OK] config.ps1 mis a jour." -ForegroundColor Green

# Relance immediate pour verifier.
try {
    Start-ScheduledTask -TaskName "IntranetMonitorAgent" -ErrorAction Stop
    Write-Host "[OK] Agent relance. Verifiez $InstallDir\agent.log dans quelques secondes." -ForegroundColor Green
} catch {
    Write-Host "[INFO] Relancez la tache 'IntranetMonitorAgent' manuellement." -ForegroundColor Yellow
}
