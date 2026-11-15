@echo off
REM Double-click to update the system (takes a backup first).
cd /d "%~dp0.."
powershell -ExecutionPolicy Bypass -File scripts\update.ps1
pause
