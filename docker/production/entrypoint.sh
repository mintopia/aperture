#!/bin/sh
set -e

# Optimise the application
php /app/artisan optimize

exec "$@"
