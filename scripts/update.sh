#!/usr/bin/env bash
# Update the system to the latest version: backup first, then download, rebuild, apply database changes, restart.
#   ./scripts/update.sh
set -euo pipefail
cd "$(dirname "$0")/.."

echo "1/5  Backup of the database before updating (./backups)"
docker compose up -d db >/dev/null
docker compose run --rm --no-deps --entrypoint sh backup /backup.sh

echo "2/5  Downloading the latest version"
git pull --ff-only

echo "3/5  Building (a few minutes)"
docker compose build

echo "4/5  Applying database changes"
docker compose run --rm app php artisan migrate --force

echo "5/5  Restarting"
docker compose up -d
docker compose ps --format 'table {{.Service}}\t{{.Status}}'
echo
echo "Updated. Refresh the page in the browser (Ctrl+F5)."
