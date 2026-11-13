@echo off
chcp 65001 >nul
rem Starts the ERP (Docker) and opens it. Double-click, or use the desktop shortcut made by Create-Shortcut.ps1.
cd /d "%~dp0.."
docker info >nul 2>&1
if errorlevel 1 (
  echo Starting Docker Desktop, please wait...
  start "" "C:\Program Files\Docker\Docker\Docker Desktop.exe"
  :wait
  timeout /t 5 /nobreak >nul
  docker info >nul 2>&1
  if errorlevel 1 goto wait
)
docker compose up -d
for /f "tokens=2 delims==" %%a in ('findstr /b "ERP_HOST=" .env') do set HOST=%%a
if "%HOST%"=="" set HOST=erp.local
start "" "https://%HOST%"
