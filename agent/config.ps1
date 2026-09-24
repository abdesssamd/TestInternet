<#
    config.ps1
    Configuration de l'agent Intranet Monitor Pro.
    Modifie ces valeurs (ou laisse install.ps1 les generer) avant le deploiement.
#>

$ServerUrl       = "http://192.168.1.10/MONITOR/server/api/report.php"
$NeighborsUrl    = "http://192.168.1.10/MONITOR/server/api/neighbors.php"
$AgentToken      = "REPLACE_WITH_TOKEN_FROM_DASHBOARD"
$IntervalSeconds = 60

$LogPath         = "C:\ProgramData\IntranetMonitor\agent.log"
$LogMaxBytes     = 5MB
$AgentVersion    = "1.0.0"

# Scan LAN (detection d'appareils non geres) : cadence independante du rapport principal,
# car un scan ARP est plus couteux et n'a pas besoin d'etre refait toutes les minutes.
# Doit rester aligne avec le reglage "ghost_scan_min_interval_seconds" cote serveur.
$GhostScanEnabled         = $true
$GhostScanIntervalMinutes = 15
$GhostScanStateFile       = "C:\ProgramData\IntranetMonitor\last_ghost_scan.txt"

# Cibles utilisees pour les tests de connectivite Internet
$ConnectTestUrl      = "https://www.google.com/generate_204"
$ConnectTestExpected = ""
$DnsTestHost         = "google.com"
$TcpTestHost         = "1.1.1.1"
$TcpTestPort         = 443

# Delais reseau (secondes)
$HttpTimeoutSeconds = 5
$TcpTimeoutMs        = 3000
