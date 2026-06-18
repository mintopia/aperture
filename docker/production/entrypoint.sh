#!/bin/sh
set -e

# Ensure storage directory structure exists (may be an empty volume mount)
mkdir -p /app/storage/framework/cache
mkdir -p /app/storage/framework/sessions
mkdir -p /app/storage/framework/views
mkdir -p /app/storage/logs

# Optimise the application
php /app/artisan optimize

exec "$@"
