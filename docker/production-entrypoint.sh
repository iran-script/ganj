#!/bin/sh
set -eu
umask 027
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
exec "$@"
