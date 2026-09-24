@echo off
REM ====================================================================
REM  INTRANET MONITOR PRO - Installation de l'agent (double-clic)
REM
REM  Ce fichier .bat evite les deux blocages rencontres avec install.ps1 :
REM    1. ExecutionPolicy : on lance PowerShell avec -ExecutionPolicy Bypass
REM    2. Droits admin    : on s'auto-eleve via UAC si besoin
REM
REM  Aucun parametre a saisir : le serveur est lu dans server.txt (place a
REM  cote de ce fichier), et l'agent s'enregistre tout seul.
REM ====================================================================

setlocal

REM --- Elevation automatique en administrateur ---
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo Elevation des privileges administrateur...
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

echo.
echo =====================================
echo  INTRANET MONITOR PRO
echo  Installation de l'agent
echo =====================================
echo.

cd /d "%~dp0"

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1"

echo.
if %errorLevel% equ 0 (
    echo Installation terminee.
) else (
    echo L'installation a rencontre une erreur. Voir les messages ci-dessus.
)
echo.
pause
