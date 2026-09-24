<#
    install-task.ps1
    Cree/recree la tache planifiee Windows qui execute agent.ps1 toutes les
    IntervalSeconds, en tant que SYSTEM, avec redemarrage automatique en cas d'echec.

    Doit etre execute en tant qu'administrateur.
#>

param(
    [string]$InstallDir = "C:\ProgramData\IntranetMonitor",
    [int]$IntervalSeconds = 60
)

$ErrorActionPreference = "Stop"
$TaskName = "IntranetMonitorAgent"
$AgentScript = Join-Path $InstallDir "agent.ps1"

if (-not (Test-Path $AgentScript)) {
    Write-Error "Script agent introuvable : $AgentScript. Executez install.ps1 d'abord."
    exit 1
}

# Supprime la tache existante si presente
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$action = New-ScheduledTaskAction -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$AgentScript`""

$intervalMinutes = [Math]::Max(1, [Math]::Round($IntervalSeconds / 60))

$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes $intervalMinutes) `
    -RepetitionDuration ([TimeSpan]::MaxValue)

$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Principal $principal -Settings $settings -Description "Intranet Monitor Pro - agent de supervision reseau" | Out-Null

Write-Host "[OK] Tache planifiee '$TaskName' creee (toutes les $intervalMinutes min, compte SYSTEM)." -ForegroundColor Green

# Lance immediatement une premiere execution
Start-ScheduledTask -TaskName $TaskName
Write-Host "[OK] Premiere execution declenchee." -ForegroundColor Green
