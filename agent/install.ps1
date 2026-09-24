<#
    install.ps1
    Installateur de l'agent Intranet Monitor Pro.

    - Cree C:\ProgramData\IntranetMonitor
    - Copie les fichiers de l'agent
    - Genere config.ps1 avec l'URL serveur et le token fournis
    - Installe la tache planifiee (install-task.ps1)
    - Envoie un premier rapport de test

    Usage (PowerShell en administrateur) :
        .\install.ps1 -ServerUrl "http://192.168.1.10/MONITOR/server/api/report.php" -AgentToken "xxxx" -IntervalSeconds 60
#>

param(
    [Parameter(Mandatory = $true)][string]$ServerUrl,
    [Parameter(Mandatory = $true)][string]$AgentToken,
    [int]$IntervalSeconds = 60,
    [string]$InstallDir = "C:\ProgramData\IntranetMonitor"
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message, [bool]$Ok = $true)
    if ($Ok) {
        Write-Host "[OK] $Message" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $Message" -ForegroundColor Red
    }
}

Write-Host "=====================================" -ForegroundColor Cyan
Write-Host " INTRANET MONITOR PRO" -ForegroundColor Cyan
Write-Host " Installation Agent" -ForegroundColor Cyan
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""

$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error "Ce script doit etre execute en tant qu'administrateur."
    exit 1
}

# --- 1. Creation du dossier ---
if (-not (Test-Path $InstallDir)) {
    New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
}
Write-Step "Dossier cree ($InstallDir)"

# --- 1b. Verrouillage des permissions ---
# Sans cela le dossier herite des droits de C:\ProgramData, ou le groupe
# Utilisateurs a un droit d'ecriture. Un utilisateur standard pourrait alors
# lire le token dans config.ps1, ou modifier agent.ps1 pour neutraliser la
# supervision tout en continuant a envoyer de faux rapports "conformes".
# On coupe donc l'heritage et on ne laisse que SYSTEM et Administrateurs.
try {
    $acl = New-Object System.Security.AccessControl.DirectorySecurity
    # $false = ne pas heriter du parent ; ACL vierge, on repart de zero.
    $acl.SetAccessRuleProtection($true, $false)

    $inherit = [System.Security.AccessControl.InheritanceFlags]"ContainerInherit, ObjectInherit"
    $noProp  = [System.Security.AccessControl.PropagationFlags]::None
    $allow   = [System.Security.AccessControl.AccessControlType]::Allow

    foreach ($sid in @('S-1-5-18', 'S-1-5-32-544')) {   # SYSTEM, Administrateurs
        $account = (New-Object System.Security.Principal.SecurityIdentifier($sid)).Translate([System.Security.Principal.NTAccount])
        $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
            $account, "FullControl", $inherit, $noProp, $allow))) | Out-Null
    }

    Set-Acl -Path $InstallDir -AclObject $acl
    Write-Step "Permissions verrouillees (SYSTEM + Administrateurs uniquement)"
} catch {
    Write-Warning "Impossible de verrouiller les permissions du dossier : $($_.Exception.Message)"
    Write-Warning "Un utilisateur standard pourrait lire le token ou alterer l'agent."
}

# --- 2. Copie des fichiers agent ---
$sourceDir = $PSScriptRoot
Copy-Item -Path (Join-Path $sourceDir "agent.ps1") -Destination $InstallDir -Force
Write-Step "Fichiers copies"

# --- 3. Generation de la configuration ---
$neighborsUrl = $ServerUrl -replace 'report\.php$', 'neighbors.php'
$configContent = @"
`$ServerUrl       = "$ServerUrl"
`$NeighborsUrl    = "$neighborsUrl"
`$AgentToken      = "$AgentToken"
`$IntervalSeconds = $IntervalSeconds

`$LogPath         = "C:\ProgramData\IntranetMonitor\agent.log"
`$LogMaxBytes     = 5MB
`$AgentVersion    = "1.0.0"

`$GhostScanEnabled         = `$true
`$GhostScanIntervalMinutes = 15
`$GhostScanStateFile       = "C:\ProgramData\IntranetMonitor\last_ghost_scan.txt"

`$ConnectTestUrl      = "https://www.msftconnecttest.com/connecttest.txt"
`$ConnectTestExpected = "Microsoft Connect Test"
`$DnsTestHost         = "www.msftconnecttest.com"
`$TcpTestHost         = "1.1.1.1"
`$TcpTestPort         = 443

`$HttpTimeoutSeconds = 5
`$TcpTimeoutMs        = 3000
"@
Set-Content -Path (Join-Path $InstallDir "config.ps1") -Value $configContent -Encoding UTF8
Write-Step "Configuration ecrite"

# --- 4. Installation de la tache planifiee ---
try {
    & (Join-Path $sourceDir "install-task.ps1") -InstallDir $InstallDir -IntervalSeconds $IntervalSeconds
    Write-Step "Tache planifiee installee"
} catch {
    Write-Step "Tache planifiee installee" $false
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}

# --- 5. Test de connexion serveur + premier rapport ---
Start-Sleep -Seconds 3
try {
    Push-Location $InstallDir
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $InstallDir "agent.ps1")
    Pop-Location
    Write-Step "Connexion serveur verifiee"
    Write-Step "Premier rapport envoye (voir agent.log pour le detail)"
} catch {
    Write-Step "Connexion serveur verifiee" $false
    Write-Host $_.Exception.Message -ForegroundColor Red
}

Write-Host ""
Write-Host "Installation terminee." -ForegroundColor Cyan
Write-Host "Log agent : $InstallDir\agent.log"
