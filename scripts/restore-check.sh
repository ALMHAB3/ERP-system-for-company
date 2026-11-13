#!/usr/bin/env bash
# Proves a backup is usable: verifies its checksum, restores it into a scratch database and runs
# the system's own integrity checks (stock ledger reconciliation + audit hash chain). Run monthly.
# Usage: PGHOST=.. PGUSER=.. PGPASSWORD=.. ./scripts/restore-check.sh backups/erp-YYYYMMDD-HHMMSS.dump
# The scratch database is left in place so you can inspect it; drop it yourself afterwards:
#   dropdb erp_restore_check
set -euo pipefail
DUMP="${1:?path to .dump file}"; SCRATCH="erp_restore_check"
cd "$(dirname "$0")/.."
( cd "$(dirname "$DUMP")" && sha256sum -c "$(basename "$DUMP").sha256" )
createdb "$SCRATCH"          # fails if the scratch database already exists - drop it first
pg_restore --no-owner --dbname="$SCRATCH" "$DUMP"
export DB_CONNECTION=pgsql DB_DATABASE="$SCRATCH" DB_HOST="${PGHOST:-127.0.0.1}" DB_PORT="${PGPORT:-5432}" DB_USERNAME="${PGUSER}" DB_PASSWORD="${PGPASSWORD:-}" DB_URL=
php artisan inventory:reconcile
php artisan audit:verify
echo "RESTORE CHECK PASSED: $DUMP  (scratch database '$SCRATCH' kept; drop it when done)"
