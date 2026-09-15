#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
STAMP="$(date +%Y%m%d-%H%M%S)"
DEST="${1:-backups/mpay-${STAMP}}"
mkdir -p "$DEST"

# Application state/config/uploads.
tar -czf "$DEST/mpay-files.tar.gz" data/config data/runtime data/public-storage

# Consistent logical DB dump. Password is read inside the MySQL container.
docker compose exec -T mysql sh -ec \
  'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  > "$DEST/mpay.sql"

echo "Backup written to $DEST"
