@echo off
cd /d "%~dp0.."
docker compose stop
echo ERP stopped. Data is kept.
pause
