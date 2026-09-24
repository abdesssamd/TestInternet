@echo off
REM ====================================================================
REM  INTRANET MONITOR PRO - Installation de l'agent (double-clic)
REM
REM  Ce fichier .bat evite les deux blocages rencontres avec install.ps1 :
REM    1. ExecutionPolicy : on lance PowerShell avec -ExecutionPolicy Bypass
REM    2. Droits admin    : on s'auto-eleve via UAC si besoin
REM
REM  AVANT DE LANCER : ouvrez server.txt et remplacez son contenu par
REM  l'adresse de votre serveur (ex: 100.10.1.136). L'installation refuse
REM  de continuer si cette valeur n'a pas ete personnalisee.
REM
REM  Aucun token a saisir : l'agent s'enregistre tout seul sur le serveur.
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
