#!/bin/sh
set -eu
if [ -z "$APP_KEY" ]; then
    echo "APP_KEY must be supplied through the deployment secret store." >&2
    exit 1
fi
php artisan config:cache --no-interaction
exec "$@"
