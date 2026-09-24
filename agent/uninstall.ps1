<#
    uninstall.ps1
    Desinstalle l'agent Intranet Monitor Pro : supprime la tache planifiee
    et le dossier ProgramData associe.

    Usage (PowerShell en administrateur) :
        .\uninstall.ps1
#>

param(
    [string]$InstallDir = "C:\ProgramData\IntranetMonitor"
)

$ErrorActionPreference = "Stop"
$TaskName = "IntranetMonitorAgent"

$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error "Ce script doit etre execute en tant qu'administrateur."
    exit 1
}

$confirmation = Read-Host "Confirmez la desinstallation de l'agent Intranet Monitor Pro (o/N)"
if ($confirmation -ne "o" -and $confirmation -ne "O") {
    Write-Host "Annule."
    exit 0
}

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "[OK] Tache planifiee supprimee." -ForegroundColor Green
} else {
    Write-Host "[INFO] Aucune tache planifiee trouvee." -ForegroundColor Yellow
}

if (Test-Path $InstallDir) {
    Remove-Item -Path $InstallDir -Recurse -Force
    Write-Host "[OK] Dossier $InstallDir supprime." -ForegroundColor Green
} else {
    Write-Host "[INFO] Dossier $InstallDir deja absent." -ForegroundColor Yellow
}

Write-Host "Desinstallation terminee." -ForegroundColor Cyan
