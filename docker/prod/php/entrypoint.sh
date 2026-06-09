#!/bin/sh
set -e

APP_ROOT=/var/www/html

# Ensure all Laravel runtime directories exist (created on every container start
# because a mounted volume may have been initialised from an empty host directory).
mkdir -p "$APP_ROOT/storage/framework/cache" \
    "$APP_ROOT/storage/framework/sessions" \
    "$APP_ROOT/storage/framework/views" \
    "$APP_ROOT/storage/logs" \
    "$APP_ROOT/bootstrap/cache"

# Ensure www-data owns the writable paths so Laravel can write logs, cache, etc.
chown -R www-data:www-data "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache" 2>/dev/null || true

# Create .env from example if missing (the file is excluded from the Docker image
# via .dockerignore). Container env vars are passed through docker-compose.
if [ ! -f "$APP_ROOT/.env" ]; then
    cp "$APP_ROOT/.env.example" "$APP_ROOT/.env"
    php artisan key:generate --force --ansi
fi

# Run any pending database migrations and seeders on startup.
# This keeps the production database schema in sync without manual steps.
php artisan migrate --force --seed --ansi 2>&1

# Start PHP-FPM in the foreground (replaces the shell process via exec)
exec php-fpm
