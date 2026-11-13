#!/usr/bin/env bash
# Wipes the WHOLE database and starts empty (use after testing, before real data). Only runs if sample data was loaded.
# Backups in ./backups are NOT touched. Type the confirmation word to proceed.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .demo-installed ] || { echo "Refusing: no sample-data marker (.demo-installed). This looks like a system with real data."; exit 1; }
read -r -p "This ERASES every record in the system. Type  RESET  to continue: " w
[ "$w" = "RESET" ] || { echo "Cancelled."; exit 1; }
docker compose down -v
docker compose up -d db redis
docker compose run --rm app php artisan migrate --force
docker compose up -d
email=${1:-admin@hubalreeh.example}
docker compose run --rm app php artisan erp:create-admin "$email"
mv .demo-installed .demo-installed.done
echo "Empty system ready (the network certificate was regenerated: re-install caddy-root.crt on devices). Sign in with the new administrator."
