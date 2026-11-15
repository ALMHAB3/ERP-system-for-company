#!/usr/bin/env bash
# End of the trial: backup, then erase every sample/test record and start real work on an empty system.
# The administrator account(s) are kept with the same e-mail and password. The network certificate is kept too.
#   ./scripts/go-live.sh
set -euo pipefail
cd "$(dirname "$0")/.."

echo "1/3  Starting the services"
docker compose up -d db redis app >/dev/null

echo "2/3  Backup of the current database (kept in ./backups, in case anything is needed later)"
docker compose run --rm --no-deps --entrypoint sh backup /backup.sh

echo "3/3  Removing the sample data"
docker compose run --rm app php artisan erp:go-live
[ -f .demo-installed ] && mv .demo-installed .demo-installed.done
docker compose up -d >/dev/null
echo
echo "Ready for real work. Sign in as the administrator: the dashboard shows the remaining setup steps."
