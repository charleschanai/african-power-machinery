#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

php artisan migrate --force
php artisan storage:link || true

php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

php artisan serve --host=0.0.0.0 --port="${PORT:-8080}" &
php artisan queue:work --sleep=3 --tries=3 --max-time=3600 &

while true; do
    php artisan schedule:run >/dev/null 2>&1 || true
    sleep 60
done &

wait
