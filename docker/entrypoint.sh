#!/bin/sh
# Prepares the app on every start. Safe to run repeatedly.
# The web container (the default command) creates, migrates and, the first time, seeds the
# database. Any other command (the queue worker) waits until the web container has done that.
set -e
cd /app

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one with: echo base64:\$(openssl rand -base64 32)" >&2
  exit 1
fi

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

if [ "$1" = "frankenphp" ]; then
  rm -f /data/app/.ready
  if [ ! -s "$DB_DATABASE" ]; then
    touch "$DB_DATABASE"
    php artisan migrate --force --seed --no-interaction
  else
    php artisan migrate --force --no-interaction
  fi
  touch /data/app/.ready
else
  i=0
  until [ -f /data/app/.ready ]; do
    i=$((i + 1))
    [ "$i" -gt 120 ] && { echo "Database is not ready after 2 minutes." >&2; exit 1; }
    sleep 1
  done
fi

exec "$@"
