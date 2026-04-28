#!/bin/sh
set -e

# Ensure storage directory structure exists (may be an empty volume mount)
mkdir -p /app/storage/framework/{cache,sessions,views}
mkdir -p /app/storage/logs

# Optimise the application
php /app/artisan optimize

exec "$@"
