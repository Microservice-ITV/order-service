#!/bin/sh
set -e
cd /var/www/html

if [ "${APP_ENV:-production}" = "production" ] && [ -z "${APP_KEY:-}" ]; then
  echo "docker-entrypoint: APP_KEY must be set when APP_ENV=production"
  exit 1
fi

php artisan package:discover --ansi --no-interaction 2>/dev/null || true

if [ "${APP_ENV:-production}" = "production" ]; then
  php artisan config:cache
  php artisan view:cache
else
  php artisan config:clear 2>/dev/null || true
fi

# (enable in production) run migrations in CI if you have multiple app replicas, not in entrypoint

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  if [ "${RUN_SEED:-false}" = "true" ]; then
    php artisan migrate --force --seed
  else
    php artisan migrate --force
  fi
elif [ "${RUN_SEED:-false}" = "true" ]; then
  php artisan db:seed --force
fi

exec docker-php-entrypoint "$@"