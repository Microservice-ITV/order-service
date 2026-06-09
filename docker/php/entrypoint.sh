#!/bin/sh

set -e

APP_ROOT=/var/www/html
AUTO_FIX_PERMISSIONS=${AUTO_FIX_PERMISSIONS:-true}

# Initialize .env if missing
if [ ! -f "$APP_ROOT/.env" ]; then
    cp "$APP_ROOT/.env.example" "$APP_ROOT/.env"
fi

# Ensure Laravel runtime/cache directories exist
mkdir -p "$APP_ROOT/storage/framework/cache"
mkdir -p "$APP_ROOT/storage/framework/sessions"
mkdir -p "$APP_ROOT/storage/framework/views"
mkdir -p "$APP_ROOT/storage/logs"
mkdir -p "$APP_ROOT/bootstrap/cache"

# Optional permission fix for cross-OS bind-mount development.
# Keep this enabled in local/dev, disable in stricter environments.
if [ "$AUTO_FIX_PERMISSIONS" = "true" ]; then
    chown -R www-data:www-data "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache" 2>/dev/null || true
    find "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache" -type d -exec chmod 775 {} \; 2>/dev/null || true
    find "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache" -type f -exec chmod 664 {} \; 2>/dev/null || true
fi

# Run migrations and seeders if not already ran
if [ ! -f "$APP_ROOT/storage/framework/.migrated" ]; then
    php artisan migrate --force --seed --ansi 2>&1
    touch "$APP_ROOT/storage/framework/.migrated"
fi

exec php-fpm
