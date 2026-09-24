<#
    agent.ps1
    Agent Windows Intranet Monitor Pro.

    Collecte les informations systeme et reseau du poste, teste l'acces
    Internet via 3 methodes independantes, puis envoie un rapport JSON
    au serveur PHP via l'API report.php.

    Usage : execute par la tache planifiee toutes les $IntervalSeconds.
            Peut aussi etre lance manuellement pour un test ponctuel.
#>

param(
    [string]$ConfigPath = (Join-Path $PSScriptRoot "config.ps1")
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $ConfigPath)) {
    Write-Error "Fichier de configuration introuvable : $ConfigPath"
    exit 1
}
. $ConfigPath

# Compatibilite ascendante : agents deja deployes avec un config.ps1 anterieur
# a l'ajout du scan LAN (variables absentes -> fonctionnalite desactivee proprement).
if (-not (Get-Variable -Name NeighborsUrl -ErrorAction SilentlyContinue)) {
    $NeighborsUrl = $ServerUrl -replace 'report\.php$', 'neighbors.php'
}
if (-not (Get-Variable -Name GhostScanEnabled -ErrorAction SilentlyContinue)) {
    $GhostScanEnabled = $false
}
if (-not (Get-Variable -Name GhostScanIntervalMinutes -ErrorAction SilentlyContinue)) {
    $GhostScanIntervalMinutes = 15
}
if (-not (Get-Variable -Name GhostScanStateFile -ErrorAction SilentlyContinue)) {
    $GhostScanStateFile = Join-Path (Split-Path $LogPath -Parent) "last_ghost_scan.txt"
}

# ---------------------------------------------------------------------
# Logging avec rotation
# ---------------------------------------------------------------------
function Write-AgentLog {
    param([string]$Message, [string]$Level = "INFO")

    $logDir = Split-Path $LogPath -Parent
    if (-not (Test-Path $logDir)) {
        New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    }

    if ((Test-Path $LogPath) -and ((Get-Item $LogPath).Length -gt $LogMaxBytes)) {
        $rotated = "$LogPath.1"
        if (Test-Path $rotated) { Remove-Item $rotated -Force }
        Rename-Item $LogPath $rotated -Force
    }

    $line = "[{0}] [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
    Add-Content -Path $LogPath -Value $line -Encoding UTF8
}

function Test-InternetHttps {
    try {
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        $resp = Invoke-WebRequest -Uri $ConnectTestUrl -TimeoutSec $HttpTimeoutSeconds -UseBasicParsing
        $sw.Stop()
        # Google repond 204 avec un corps vide (generate_204) ; d'autres
        # endpoints repondent 200 avec un contenu attendu. On accepte les deux :
        # si $ConnectTestExpected est vide, seul le code HTTP compte.
        if ([string]::IsNullOrWhiteSpace($ConnectTestExpected)) {
            $ok = ($resp.StatusCode -eq 200 -or $resp.StatusCode -eq 204)
        } else {
            $ok = ($resp.StatusCode -eq 200) -and ($resp.Content.Trim() -eq $ConnectTestExpected)
        }
        return @{ Ok = $ok; LatencyMs = $sw.ElapsedMilliseconds }
    } catch {
        return @{ Ok = $false; LatencyMs = $null; Error = $_.Exception.Message }
    }
}

function Test-InternetDns {
    try {
        $result = Resolve-DnsName -Name $DnsTestHost -ErrorAction Stop
        return @{ Ok = ($null -ne $result) }
    } catch {
        return @{ Ok = $false; Error = $_.Exception.Message }
    }
}

function Test-InternetTcp {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $iar = $client.BeginConnect($TcpTestHost, $TcpTestPort, $null, $null)
        $success = $iar.AsyncWaitHandle.WaitOne($TcpTimeoutMs, $false)
        if ($success -and $client.Connected) {
            $client.EndConnect($iar)
            $client.Close()
            return @{ Ok = $true }
        }
        $client.Close()
        return @{ Ok = $false }
    } catch {
        return @{ Ok = $false; Error = $_.Exception.Message }
    }
}

function Get-InternetStatus {
    $https = Test-InternetHttps
    $dns = Test-InternetDns
    $tcp = Test-InternetTcp

    $allOk = $https.Ok -and $dns.Ok -and $tcp.Ok

    return @{
        Status  = $allOk
        Latency = $https.LatencyMs
        Detail  = @{ https = $https.Ok; dns = $dns.Ok; tcp = $tcp.Ok }
    }
}

function Get-NetworkAdapterReport {
    $adapters = @()

    try {
        $netAdapters = Get-NetAdapter | Where-Object { $_.Virtual -eq $false }
    } catch {
        Write-AgentLog "Get-NetAdapter a echoue : $($_.Exception.Message)" "WARN"
        return $adapters
    }

    foreach ($na in $netAdapters) {
        $type = "OTHER"
        if ($na.InterfaceDescription -match "Wi-?Fi|Wireless|802\.11") { $type = "WIFI" }
        elseif ($na.InterfaceDescription -match "Ethernet|Gigabit|Realtek|Intel.*Connection") { $type = "ETHERNET" }
        elseif ($na.MediaType -eq "802.3") { $type = "ETHERNET" }
        elseif ($na.MediaType -eq "Native 802.11") { $type = "WIFI" }

        $status = if ($na.Status -eq "Up") { "UP" } else { "DOWN" }

        $ipv4 = $null
        $gateway = $null
        try {
            $ipConfig = Get-NetIPConfiguration -InterfaceIndex $na.ifIndex -ErrorAction SilentlyContinue
            if ($ipConfig) {
                $ipv4 = ($ipConfig.IPv4Address | Select-Object -First 1).IPAddress
                $gateway = ($ipConfig.IPv4DefaultGateway | Select-Object -First 1).NextHop
            }
        } catch {}

        $ssid = $null
        if ($type -eq "WIFI" -and $status -eq "UP") {
            try {
                $wlanOutput = netsh wlan show interfaces
                $ssidLine = $wlanOutput | Select-String "^\s*SSID\s*:\s*(.+)$" | Select-Object -First 1
                if ($ssidLine) {
                    $ssid = $ssidLine.Matches[0].Groups[1].Value.Trim()
                }
            } catch {}
        }

        $adapters += @{
            name    = $na.Name
            type    = $type
            status  = $status
            ipv4    = $ipv4
            mac     = $na.MacAddress
            gateway = $gateway
            ssid    = $ssid
        }
    }

    return $adapters
}

function Build-Report {
    $adapters = Get-NetworkAdapterReport
    $internet = Get-InternetStatus

    $ethernetActive = [bool]($adapters | Where-Object { $_.type -eq "ETHERNET" -and $_.status -eq "UP" -and $_.ipv4 })
    $wifiAdapter = $adapters | Where-Object { $_.type -eq "WIFI" -and $_.status -eq "UP" -and $_.ipv4 } | Select-Object -First 1
    $wifiActive = [bool]$wifiAdapter

    $primaryAdapter = $adapters | Where-Object { $_.status -eq "UP" -and $_.ipv4 } | Select-Object -First 1

    $os = Get-CimInstance Win32_OperatingSystem
    $cs = Get-CimInstance Win32_ComputerSystem

    $report = @{
        hostname             = $env:COMPUTERNAME
        os_name              = $os.Caption
        os_version           = $os.Version
        current_user         = $env:USERNAME
        domain               = $cs.Domain
        primary_ip           = $primaryAdapter.ipv4
        primary_mac          = $primaryAdapter.mac
        gateway              = $primaryAdapter.gateway
        ethernet_active      = $ethernetActive
        wifi_active           = $wifiActive
        wifi_ssid            = $wifiAdapter.ssid
        internet_status      = $internet.Status
        internet_test        = $internet.Detail
        internet_latency     = $internet.Latency
        internet_checked_at  = (Get-Date -Format "yyyy-MM-dd HH:mm:ss")
        agent_version        = $AgentVersion
        # Etat reel du blocage sur le poste : permet au serveur de distinguer
        # "consigne posee" de "consigne effectivement appliquee".
        internet_block_applied = (Test-InternetBlockActive)
        adapters             = $adapters
    }

    return $report
}

# ---------------------------------------------------------------------
# Coupure d'Internet a distance
#
# Principe : on NE desactive PAS la carte reseau (le poste deviendrait
# injoignable et ne pourrait plus recevoir l'ordre inverse). On pose des
# regles de pare-feu Windows qui bloquent tout le trafic sortant SAUF les
# plages privees RFC1918. Resultat : Internet coupe, LAN et supervision
# toujours operationnels.
# ---------------------------------------------------------------------

$FirewallRuleName = "IntranetMonitor - Blocage Internet"

function Test-InternetBlockActive {
    try {
        $rule = Get-NetFirewallRule -DisplayName $FirewallRuleName -ErrorAction SilentlyContinue
        return ($null -ne $rule)
    } catch {
        return $false
    }
}

function Enable-InternetBlock {
    if (Test-InternetBlockActive) { return $true }
    try {
        # Bloque tout le sortant, puis on s'appuie sur le fait qu'une regle
        # Allow est prioritaire sur un Block dans le pare-feu Windows pour
        # laisser passer le LAN (RFC1918) et le loopback.
        New-NetFirewallRule -DisplayName "$FirewallRuleName (LAN autorise)" `
            -Direction Outbound -Action Allow -Profile Any `
            -RemoteAddress @('10.0.0.0/8','172.16.0.0/12','192.168.0.0/16','127.0.0.0/8','224.0.0.0/4') `
            -ErrorAction Stop | Out-Null

        New-NetFirewallRule -DisplayName $FirewallRuleName `
            -Direction Outbound -Action Block -Profile Any `
            -RemoteAddress Any -ErrorAction Stop | Out-Null

        Write-AgentLog "Acces Internet coupe (regles pare-feu posees, LAN conserve)." "WARN"
        return $true
    } catch {
        Write-AgentLog "Echec de la coupure Internet : $($_.Exception.Message)" "ERROR"
        return $false
    }
}

function Disable-InternetBlock {
    if (-not (Test-InternetBlockActive)) { return $true }
    try {
        Get-NetFirewallRule -DisplayName "$FirewallRuleName*" -ErrorAction SilentlyContinue |
            Remove-NetFirewallRule -ErrorAction Stop
        Write-AgentLog "Acces Internet retabli (regles pare-feu retirees)."
        return $true
    } catch {
        Write-AgentLog "Echec du retablissement Internet : $($_.Exception.Message)" "ERROR"
        return $false
    }
}

function Sync-InternetBlockState {
    param([bool]$ShouldBlock)

    $isBlocked = Test-InternetBlockActive
    if ($ShouldBlock -and -not $isBlocked) {
        Enable-InternetBlock | Out-Null
    } elseif (-not $ShouldBlock -and $isBlocked) {
        Disable-InternetBlock | Out-Null
    }
}

function Send-Report {
    param([hashtable]$Report)

    $json = $Report | ConvertTo-Json -Depth 6 -Compress

    $headers = @{
        "X-Agent-Token" = $AgentToken
        "X-Agent-Host"  = $env:COMPUTERNAME
        "Content-Type"  = "application/json"
    }

    try {
        $response = Invoke-RestMethod -Uri $ServerUrl -Method Post -Body $json -Headers $headers -TimeoutSec 15
        Write-AgentLog "Rapport envoye avec succes."

        # Le serveur renvoie la consigne de coupure dans sa reponse : c'est le
        # canal descendant (aucun port n'est ouvert sur le poste).
        if ($response -and $response.commands) {
            try {
                Sync-InternetBlockState -ShouldBlock ([bool]$response.commands.block_internet)
            } catch {
                Write-AgentLog "Erreur application consigne Internet : $($_.Exception.Message)" "ERROR"
            }
        }

        return $true
    } catch {
        $statusCode = $null
        if ($_.Exception.Response) {
            try { $statusCode = [int]$_.Exception.Response.StatusCode } catch {}
        }
        Write-AgentLog "Echec envoi rapport (HTTP $statusCode) : $($_.Exception.Message)" "ERROR"
        return $false
    }
}

# ---------------------------------------------------------------------
# Scan LAN (detection d'appareils non geres via la table ARP locale)
# ---------------------------------------------------------------------
function Get-ArpNeighbors {
    $neighbors = @()
    try {
        $entries = Get-NetNeighbor -AddressFamily IPv4 -ErrorAction Stop |
            Where-Object { $_.State -notin @('Unreachable', 'Incomplete') -and $_.LinkLayerAddress -match '^[0-9A-Fa-f]{2}(-[0-9A-Fa-f]{2}){5}$' }

        $ownMacs = (Get-NetAdapter | Select-Object -ExpandProperty MacAddress) -replace ':', '-'

        foreach ($e in $entries) {
            $mac = $e.LinkLayerAddress
            if ($mac -eq '00-00-00-00-00-00' -or $mac -eq 'FF-FF-FF-FF-FF-FF') { continue }
            if ($mac -match '^01-00-5E') { continue } # multicast
            if ($ownMacs -contains $mac) { continue }  # ne pas se signaler soi-meme

            $neighbors += @{ mac = $mac; ip = $e.IPAddress }
        }
    } catch {
        Write-AgentLog "Get-ArpNeighbors a echoue : $($_.Exception.Message)" "WARN"
    }
    return $neighbors
}

function Send-Neighbors {
    param([array]$Neighbors)

    if ($Neighbors.Count -eq 0) {
        return $true
    }

    $json = @{ neighbors = $Neighbors } | ConvertTo-Json -Depth 4 -Compress
    $headers = @{
        "X-Agent-Token" = $AgentToken
        "X-Agent-Host"  = $env:COMPUTERNAME
        "Content-Type"  = "application/json"
    }

    try {
        Invoke-RestMethod -Uri $NeighborsUrl -Method Post -Body $json -Headers $headers -TimeoutSec 20 | Out-Null
        Write-AgentLog "Scan LAN envoye avec succes ($($Neighbors.Count) voisin(s))."
        return $true
    } catch {
        Write-AgentLog "Echec envoi scan LAN : $($_.Exception.Message)" "WARN"
        return $false
    }
}

function Invoke-GhostScanIfDue {
    if (-not $GhostScanEnabled) {
        return
    }

    $due = $true
    if (Test-Path $GhostScanStateFile) {
        try {
            $lastRun = Get-Date (Get-Content $GhostScanStateFile -Raw)
            $due = ((Get-Date) - $lastRun).TotalMinutes -ge $GhostScanIntervalMinutes
        } catch {
            $due = $true
        }
    }

    if (-not $due) {
        return
    }

    Write-AgentLog "Scan LAN (ARP) declenche."
    $neighbors = Get-ArpNeighbors
    if (Send-Neighbors -Neighbors $neighbors) {
        $stateDir = Split-Path $GhostScanStateFile -Parent
        if (-not (Test-Path $stateDir)) { New-Item -ItemType Directory -Path $stateDir -Force | Out-Null }
        Set-Content -Path $GhostScanStateFile -Value (Get-Date -Format "o") -Encoding UTF8
    }
}

# ---------------------------------------------------------------------
# Execution principale avec retry
# ---------------------------------------------------------------------
function Invoke-AgentCycle {
    Write-AgentLog "Debut du cycle de collecte."

    try {
        $report = Build-Report
    } catch {
        Write-AgentLog "Erreur lors de la collecte des donnees : $($_.Exception.Message)" "ERROR"
        return $false
    }

    $maxRetries = 3
    $attempt = 0
    $success = $false

    while ($attempt -lt $maxRetries -and -not $success) {
        $attempt++
        $success = Send-Report -Report $report
        if (-not $success -and $attempt -lt $maxRetries) {
            Write-AgentLog "Nouvelle tentative ($attempt/$maxRetries) dans 5s..." "WARN"
            Start-Sleep -Seconds 5
        }
    }

    if (-not $success) {
        Write-AgentLog "Echec definitif apres $maxRetries tentatives." "ERROR"
    }

    try {
        Invoke-GhostScanIfDue
    } catch {
        Write-AgentLog "Erreur durant le scan LAN : $($_.Exception.Message)" "WARN"
    }

    return $success
}

Invoke-AgentCycle | Out-Null
