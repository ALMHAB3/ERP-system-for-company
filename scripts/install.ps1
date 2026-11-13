# Windows setup (PowerShell). Requires Docker Desktop. Run:  powershell -ExecutionPolicy Bypass -File scripts\install.ps1
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')
function Rand { -join ((48..57)+(65..90)+(97..122) | Get-Random -Count 28 | ForEach-Object { [char]$_ }) }
function GetEnv($k) { if (Test-Path .env) { $l = Select-String -Path .env -Pattern "^$k=" | Select-Object -First 1; if ($l) { return $l.Line.Substring($k.Length + 1) } }; return '' }
function SetEnv($k, $v) {
  $lines = if (Test-Path .env) { Get-Content .env } else { @() }
  if ($lines | Where-Object { $_ -match "^$k=" }) { $lines = $lines | ForEach-Object { if ($_ -match "^$k=") { "$k=$v" } else { $_ } } } else { $lines += "$k=$v" }
  Set-Content -Path .env -Value $lines -Encoding ascii
}
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker Desktop is not installed.' }
Write-Host "`n1/6 Settings"
if (-not (Test-Path .env)) { Copy-Item .env.production.example .env }
$pw = GetEnv 'DB_PASSWORD'; if (-not $pw -or $pw.StartsWith('CHANGE-ME')) { SetEnv 'DB_PASSWORD' (Rand) }
$h = Read-Host 'Name used to open the system on the network [erp.local]'; if (-not $h) { $h = 'erp.local' }
SetEnv 'ERP_HOST' $h; SetEnv 'APP_URL' "https://$h"; SetEnv 'APP_ENV' 'production'; SetEnv 'APP_DEBUG' 'false'
Write-Host "`n2/6 Building (first time: a few minutes)"; docker compose build
Write-Host "`n3/6 Application key"
if (-not (GetEnv 'APP_KEY')) { $k = (docker compose run --rm --no-deps -T app php artisan key:generate --show | Select-Object -Last 1).Trim(); SetEnv 'APP_KEY' $k }
Write-Host "`n4/6 Starting services"
docker compose up -d db redis; docker compose run --rm app php artisan migrate --force; docker compose up -d
Write-Host "`n5/6 First administrator"
$e = Read-Host 'Administrator e-mail [admin@hubalreeh.example]'; if (-not $e) { $e = 'admin@hubalreeh.example' }
docker compose run --rm app php artisan erp:create-admin $e
Write-Host "`n6/6 Sample data (TEST data: start from an empty database before real data)"
$l = Read-Host 'Load sample data and demo users now? [Y/n]'
if (-not $l -or $l -match '^[Yy]') { docker compose run --rm app php artisan erp:demo-data --with-users; New-Item .demo-installed -ItemType File -Force | Out-Null }
Start-Sleep 3; docker compose cp web:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt 2>$null
Write-Host "`nReady: https://$h   (install caddy-root.crt on each device once). See docs\QUICKSTART.md"
