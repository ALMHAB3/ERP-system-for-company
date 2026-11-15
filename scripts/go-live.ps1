# End of the trial (Windows): backup, then erase every sample/test record and start real work on an empty system.
# The administrator account(s) are kept with the same e-mail and password.
#   powershell -ExecutionPolicy Bypass -File scripts\go-live.ps1
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')
function Run { & $args[0] $args[1..($args.Length - 1)]; if ($LASTEXITCODE -ne 0) { throw "Command failed: $($args -join ' ')" } }

Write-Host '1/3  Starting the services'
Run docker compose up -d db redis app
Write-Host '2/3  Backup of the current database (kept in .\backups)'
Run docker compose run --rm --no-deps --entrypoint sh backup /backup.sh
Write-Host '3/3  Removing the sample data'
Run docker compose run --rm app php artisan erp:go-live
if (Test-Path .demo-installed) { Move-Item .demo-installed .demo-installed.done -Force }
Run docker compose up -d
Write-Host "`nReady for real work. Sign in as the administrator: the dashboard shows the remaining setup steps."
