# Update the system to the latest version (Windows): backup first, then download, rebuild, apply database changes, restart.
#   powershell -ExecutionPolicy Bypass -File scripts\update.ps1
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')
function Run { & $args[0] $args[1..($args.Length - 1)]; if ($LASTEXITCODE -ne 0) { throw "Command failed: $($args -join ' ')" } }

Write-Host '1/5  Backup of the database before updating (.\backups)'
Run docker compose up -d db
Run docker compose run --rm --no-deps --entrypoint sh backup /backup.sh
Write-Host '2/5  Downloading the latest version'
Run git pull --ff-only
Write-Host '3/5  Building (a few minutes)'
Run docker compose build
Write-Host '4/5  Applying database changes'
Run docker compose run --rm app php artisan migrate --force
Write-Host '5/5  Restarting'
Run docker compose up -d
Write-Host "`nUpdated. Refresh the page in the browser (Ctrl+F5)."
