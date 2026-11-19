#!/bin/sh
set -e

# Expose the Vite build (baked into the image at /opt/public-build) to the
# nginx container through the shared public-build volume.
mkdir -p /var/www/html/public/build
cp -r /opt/public-build/. /var/www/html/public/build/

# One-shot setup: create the SQLite file and run migrations. Only the app
# service does this; queue and scheduler start after it.
if [ "$RUN_MIGRATIONS" = "1" ]; then
    touch /var/www/html/database/database.sqlite
    php artisan migrate --force
fi

exec "$@"
