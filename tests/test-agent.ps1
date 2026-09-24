<#
    test-agent.ps1
    Verifie pas a pas que l'agent est capable de fonctionner correctement :
    PowerShell, LAN, DNS, Internet, serveur PHP, API, authentification, JSON, reponse HTTP.

    Usage :
        .\test-agent.ps1
        .\test-agent.ps1 -ConfigPath "C:\ProgramData\IntranetMonitor\config.ps1"
#>

param(
    [string]$ConfigPath = (Join-Path $PSScriptRoot "config.ps1")
)

$results = [ordered]@{}

function Test-Step {
    param([string]$Name, [scriptblock]$Test)
    try {
        $ok = & $Test
        $results[$Name] = [bool]$ok
    } catch {
        $results[$Name] = $false
    }
}

Write-Host "=====================================" -ForegroundColor Cyan
Write-Host " INTRANET MONITOR PRO - Test agent" -ForegroundColor Cyan
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""

# --- PowerShell version ---
Test-Step "POWERSHELL" { $PSVersionTable.PSVersion.Major -ge 5 }

# --- Configuration ---
if (-not (Test-Path $ConfigPath)) {
    Write-Host "[FAIL] Fichier de configuration introuvable : $ConfigPath" -ForegroundColor Red
    exit 1
}
. $ConfigPath
$results["CONFIG"] = $true

# --- LAN : passerelle joignable ---
Test-Step "LAN" {
    $gw = (Get-NetRoute -DestinationPrefix "0.0.0.0/0" -ErrorAction SilentlyContinue | Select-Object -First 1).NextHop
    if (-not $gw) { return $false }
    return (Test-Connection -ComputerName $gw -Count 1 -Quiet -ErrorAction SilentlyContinue)
}

# --- DNS ---
Test-Step "DNS" {
    $r = Resolve-DnsName -Name $DnsTestHost -ErrorAction Stop
    return $null -ne $r
}

# --- Internet (HTTPS) ---
Test-Step "INTERNET" {
    $resp = Invoke-WebRequest -Uri $ConnectTestUrl -TimeoutSec $HttpTimeoutSeconds -UseBasicParsing
    return ($resp.StatusCode -eq 200) -and ($resp.Content.Trim() -eq $ConnectTestExpected)
}

# --- Serveur PHP joignable ---
$serverBase = $ServerUrl -replace '/api/report\.php$', ''
Test-Step "SERVER" {
    try {
        $resp = Invoke-WebRequest -Uri $serverBase -TimeoutSec $HttpTimeoutSeconds -UseBasicParsing -ErrorAction Stop
        return $true
    } catch {
        # Un 302/401/403 signifie que le serveur repond quand meme
        return $_.Exception.Response -ne $null
    }
}

# --- API + authentification + JSON + reponse HTTP (test reel, non destructif) ---
$apiOk = $false
$authOk = $false
$jsonOk = $false
$reportOk = $false

try {
    $testPayload = @{
        hostname        = $env:COMPUTERNAME
        os_name         = "TEST"
        adapters        = @()
        internet_status = $false
    } | ConvertTo-Json -Compress

    $headers = @{ "X-Agent-Token" = $AgentToken; "Content-Type" = "application/json" }

    try {
        $response = Invoke-WebRequest -Uri $ServerUrl -Method Post -Body $testPayload -Headers $headers -TimeoutSec 15 -UseBasicParsing
        $apiOk = $true
        $authOk = ($response.StatusCode -eq 200)
        $jsonOk = $true
        $reportOk = ($response.StatusCode -eq 200)
    } catch {
        $apiOk = $true # le endpoint a repondu (meme en erreur HTTP)
        $status = $null
        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
        }
        $authOk = ($status -ne 401)
        $jsonOk = $false
        $reportOk = $false
    }
} catch {
    $apiOk = $false
}

$results["API"] = $apiOk
$results["AUTHENTICATION"] = $authOk
$results["JSON"] = $jsonOk
$results["REPORT"] = $reportOk

Write-Host ""
foreach ($key in $results.Keys) {
    $status = if ($results[$key]) { "OK" } else { "FAIL" }
    $color = if ($results[$key]) { "Green" } else { "Red" }
    Write-Host ("{0,-16}{1}" -f $key, $status) -ForegroundColor $color
}

Write-Host ""
if ($results.Values -contains $false) {
    Write-Host "Certains tests ont echoue. Consultez le detail ci-dessus." -ForegroundColor Yellow
    exit 1
} else {
    Write-Host "Tous les tests sont OK." -ForegroundColor Green
    exit 0
}
