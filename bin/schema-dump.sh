#!/usr/bin/env bash
# See docs/decisions/adr-014-squashed-schema-dumps.md
set -euo pipefail
cd "$(dirname "$0")/.."

MODE=${1:-generate}
SCRATCH_DB=${SCHEMA_DUMP_DATABASE:-aperture_schema_dump}
WORK_DIR=$(mktemp -d)
SQLITE_FILE="$WORK_DIR/schema.sqlite"
BACKUP_DIR="$WORK_DIR/backup"
trap 'rm -rf "$WORK_DIR"' EXIT

DUMP_BIN=$(command -v mariadb-dump || command -v mysqldump)
mkdir "$WORK_DIR/bin"
ln -s "$DUMP_BIN" "$WORK_DIR/bin/mysqldump"
export PATH="$WORK_DIR/bin:$PATH"

CLIENT=$(command -v mariadb || command -v mysql)
CLIENT_ARGS=(-u"${DB_USERNAME:-root}")
[ -n "${DB_PASSWORD:-}" ] && CLIENT_ARGS+=(-p"$DB_PASSWORD")
if [ -n "${DB_SOCKET:-}" ]; then
    CLIENT_ARGS+=(--socket="$DB_SOCKET")
else
    CLIENT_ARGS+=(-h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}")
fi

normalize() {
    grep -v -E '^(/\*|--|$)' "$1" | sed -E 's/ AUTO_INCREMENT=[0-9]+//'
}

columns() {
    DB_CONNECTION=$1 DB_DATABASE=$2 php artisan tinker --execute='foreach (Schema::getTables() as $t) { if ($t["name"] === "sqlite_sequence") { continue; } foreach (Schema::getColumns($t["name"]) as $c) { echo $t["name"].".".$c["name"].PHP_EOL; } }' | sort -u
}

mkdir "$BACKUP_DIR"
cp database/schema/*.sql "$BACKUP_DIR"/
PRUNE=()
[ "$MODE" = "--check" ] || PRUNE=(--prune)

touch "$SQLITE_FILE"
DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_FILE" php artisan migrate --force --no-interaction
DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_FILE" php artisan schema:dump --database=sqlite

"$CLIENT" "${CLIENT_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`; CREATE DATABASE \`$SCRATCH_DB\`"
DB_CONNECTION=mysql DB_DATABASE="$SCRATCH_DB" php artisan migrate --force --no-interaction
DB_CONNECTION=mysql DB_DATABASE="$SCRATCH_DB" php artisan schema:dump --database=mysql "${PRUNE[@]}"

if [ "$MODE" != "--check" ]; then
    "$CLIENT" "${CLIENT_ARGS[@]}" -e "DROP DATABASE \`$SCRATCH_DB\`"
    find database/migrations -name '*.php' -delete
    echo "Schema dumps regenerated; folded migrations pruned"
    exit 0
fi

STATUS=0
columns sqlite "$SQLITE_FILE" > "$WORK_DIR/sqlite.columns"
columns mysql "$SCRATCH_DB" > "$WORK_DIR/mysql.columns"
"$CLIENT" "${CLIENT_ARGS[@]}" -e "DROP DATABASE \`$SCRATCH_DB\`"
if ! diff <(grep -v '^migrations\.' "$WORK_DIR/sqlite.columns") <(grep -v '^migrations\.' "$WORK_DIR/mysql.columns"); then
    echo "sqlite and mysql schemas differ after applying dumps and pending migrations"
    STATUS=1
fi

if ! compgen -G 'database/migrations/*.php' > /dev/null; then
    for f in mysql-schema.sql sqlite-schema.sql; do
        if ! diff <(normalize "$BACKUP_DIR/$f") <(normalize "database/schema/$f") > /dev/null; then
            echo "database/schema/$f is out of date"
            STATUS=1
        fi
    done
fi

cp "$BACKUP_DIR"/*.sql database/schema/
[ "$STATUS" -eq 0 ] && echo "Schema dumps match migrations"
exit "$STATUS"
