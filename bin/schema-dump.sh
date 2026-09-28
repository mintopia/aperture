#!/usr/bin/env bash
# Regenerates database/schema/{mysql,sqlite}-schema.sql from schema dump + pending migrations.
#   bin/schema-dump.sh          regenerate both dumps and DELETE every migration file (they are folded into the dumps);
#                               only run once all pending migrations have shipped to production
#   bin/schema-dump.sh --check  fail if the committed dumps differ from a fresh build (CI); with pending
#                               migrations it only proves they apply on both connections
# The mysql dump needs a MariaDB server: set DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD (or DB_SOCKET).
# SCHEMA_DUMP_DATABASE names the scratch database (default aperture_schema_dump); it is dropped and recreated.
set -euo pipefail
cd "$(dirname "$0")/.."

MODE=${1:-generate}
SCRATCH_DB=${SCHEMA_DUMP_DATABASE:-aperture_schema_dump}
SQLITE_FILE=$(mktemp)
BACKUP_DIR=$(mktemp -d)
trap 'rm -rf "$SQLITE_FILE" "$BACKUP_DIR"' EXIT

MYSQL_ARGS=(-u"${DB_USERNAME:-root}")
[ -n "${DB_PASSWORD:-}" ] && MYSQL_ARGS+=(-p"$DB_PASSWORD")
if [ -n "${DB_SOCKET:-}" ]; then
    MYSQL_ARGS+=(--socket="$DB_SOCKET")
else
    MYSQL_ARGS+=(-h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}")
fi

cp -r database/schema/. "$BACKUP_DIR"/
PRUNE=()
[ "$MODE" = "--check" ] || PRUNE=(--prune)

echo "▸ sqlite"
DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_FILE" php artisan migrate --force --no-interaction
DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_FILE" php artisan schema:dump --database=sqlite

echo "▸ mysql ($SCRATCH_DB)"
mysql "${MYSQL_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`; CREATE DATABASE \`$SCRATCH_DB\`"
DB_CONNECTION=mysql DB_DATABASE="$SCRATCH_DB" php artisan migrate --force --no-interaction
DB_CONNECTION=mysql DB_DATABASE="$SCRATCH_DB" php artisan schema:dump --database=mysql "${PRUNE[@]}"
mysql "${MYSQL_ARGS[@]}" -e "DROP DATABASE \`$SCRATCH_DB\`"

if [ "$MODE" = "--check" ]; then
    if compgen -G 'database/migrations/*.php' > /dev/null; then
        cp "$BACKUP_DIR"/* database/schema/
        echo "✓ pending migrations apply on sqlite and mysql (dump comparison skipped)"
        exit 0
    fi
    STATUS=0
    for f in mysql-schema.sql sqlite-schema.sql; do
        cmp -s "$BACKUP_DIR/$f" "database/schema/$f" || { echo "✕ database/schema/$f is out of date"; STATUS=1; }
    done
    cp "$BACKUP_DIR"/* database/schema/
    [ "$STATUS" -eq 0 ] && echo "✓ schema dumps match migrations"
    [ "$STATUS" -eq 0 ] || echo "Run bin/schema-dump.sh and commit the result."
    exit "$STATUS"
fi

if [ -d database/migrations ]; then
    find database/migrations -name '*.php' -delete
fi
echo "✓ schema dumps regenerated; folded migrations pruned"
