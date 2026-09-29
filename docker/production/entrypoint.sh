#!/bin/sh
set -e

WRITABLE="/app/storage /app/bootstrap/cache /data/caddy /config/caddy"

if [ "$(id -u)" = "0" ]; then
    case "$PUID$PGID" in
        *[!0-9]*|'') echo "PUID and PGID must be numeric (got PUID='$PUID' PGID='$PGID')" >&2; exit 1 ;;
    esac

    # Volumes may be empty or owned by a previous UID; only walk trees whose root is wrong
    mkdir -p $WRITABLE
    for dir in $WRITABLE; do
        if [ "$(stat -c '%u:%g' "$dir")" != "$PUID:$PGID" ]; then
            chown -R "$PUID:$PGID" "$dir"
        fi
    done

    RUN_AS="su-exec $PUID:$PGID"
else
    RUN_AS=""
fi

# Ensure storage directory structure exists (may be an empty volume mount)
$RUN_AS mkdir -p \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs

# Optimise the application
$RUN_AS php /app/artisan optimize

exec $RUN_AS "$@"
