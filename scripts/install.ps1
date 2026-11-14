# Windows setup (PowerShell). Requires Docker Desktop. Run:  powershell -ExecutionPolicy Bypass -File scripts\install.ps1
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')
function Rand { -join ((48..57)+(65..90)+(97..122) | Get-Random -Count 28 | ForEach-Object { [char]$_ }) }
function GetEnv($k) { if (Test-Path .env) { $l = Select-String -Path .env -Pattern "^$k=" | Select-Object -First 1; if ($l) { return $l.Line.Substring($k.Length + 1) } }; return '' }
function SetEnv($k, $v) {
  $lines = if (Test-Path .env) { Get-Content .env } else { @() }
  if ($lines | Where-Object { $_ -match "^$k=" }) { $lines = $lines | ForEach-Object { if ($_ -match "^$k=") { "$k=$v" } else { $_ } } } else { $lines += "$k=$v" }
  [IO.File]::WriteAllLines((Join-Path (Get-Location) '.env'), [string[]]$lines, (New-Object Text.UTF8Encoding($false)))   # UTF-8 without BOM: the Arabic company name must survive
}
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker Desktop is not installed.' }
# Every docker call must succeed, otherwise stop (a native command failing does not stop PowerShell by itself).
function Run { & $args[0] $args[1..($args.Length - 1)]; if ($LASTEXITCODE -ne 0) { throw "Command failed: $($args -join ' ')" } }
docker info *> $null
if ($LASTEXITCODE -ne 0) {
  Write-Host 'Docker is not running. Starting Docker Desktop and waiting (up to 3 minutes)...'
  $exe = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'; if (-not (Test-Path $exe)) { $exe = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\Docker Desktop.exe' }
  if (Test-Path $exe) { Start-Process $exe }
  for ($i = 0; $i -lt 36; $i++) { Start-Sleep 5; docker info *> $null; if ($LASTEXITCODE -eq 0) { break } }
  docker info *> $null
  if ($LASTEXITCODE -ne 0) { throw 'Docker did not start. Open Docker Desktop yourself, wait for "Engine running", then run this script again.' }
}
Write-Host "`n1/6 Settings"
if (-not (Test-Path .env)) { Copy-Item .env.production.example .env }
$pw = GetEnv 'DB_PASSWORD'; if (-not $pw -or $pw.StartsWith('CHANGE-ME')) { SetEnv 'DB_PASSWORD' (Rand) }
$h = Read-Host 'Name used to open the system on the network [erp.local]'; if (-not $h) { $h = 'erp.local' }
SetEnv 'ERP_HOST' $h; SetEnv 'APP_URL' "https://$h"; SetEnv 'APP_ENV' 'production'; SetEnv 'APP_DEBUG' 'false'
Write-Host "`n2/6 Building (first time: a few minutes)"; Run docker compose build
Write-Host "`n3/6 Application key"
if (-not (GetEnv 'APP_KEY')) { $out = docker compose run --rm --no-deps -T app php artisan key:generate --show; if ($LASTEXITCODE -ne 0) { throw 'Could not generate the application key.' }; SetEnv 'APP_KEY' (($out | Select-Object -Last 1).ToString().Trim()) }
Write-Host "`n4/6 Starting services"
Run docker compose up -d db redis; Run docker compose run --rm app php artisan migrate --force; Run docker compose up -d
Write-Host "`n5/6 First administrator"
$e = Read-Host 'Administrator e-mail [admin@hubalreeh.example]'; if (-not $e) { $e = 'admin@hubalreeh.example' }
Run docker compose run --rm app php artisan erp:create-admin $e
Write-Host "`n6/6 Sample data (TEST data: start from an empty database before real data)"
$l = Read-Host 'Load sample data and demo users now? [Y/n]'
if (-not $l -or $l -match '^[Yy]') { Run docker compose run --rm app php artisan erp:demo-data --with-users; New-Item .demo-installed -ItemType File -Force | Out-Null }
Start-Sleep 3
$ErrorActionPreference = 'Continue'   # docker prints progress on stderr; that is not a failure
docker compose cp web:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt *> $null
$ErrorActionPreference = 'Stop'
if (Test-Path caddy-root.crt) { Write-Host 'Saved caddy-root.crt (install it once on each device that opens the system).' }
Write-Host "`nReady: https://$h   (install caddy-root.crt on each device once). See docs\QUICKSTART.md"
