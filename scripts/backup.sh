#!/bin/sh
# Consistent PostgreSQL dump (custom format) + checksum. Exits non-zero on any failure.
# Reads PGHOST/PGUSER/PGPASSWORD/PGDATABASE from the environment.
# NOTE: this script never deletes anything. Old dumps are pruned by you (see docs/LOCAL-DEPLOYMENT.md).
set -eu
DIR="${BACKUP_DIR:-./backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"; FILE="$DIR/erp-$STAMP.dump"
mkdir -p "$DIR"
pg_dump --format=custom --no-owner --file="$FILE.partial" && mv "$FILE.partial" "$FILE"
pg_restore --list "$FILE" > /dev/null                      # the archive must be readable
( cd "$DIR" && sha256sum "$(basename "$FILE")" > "$(basename "$FILE").sha256" )
echo "backup ok: $FILE ($(du -h "$FILE" | cut -f1))"
