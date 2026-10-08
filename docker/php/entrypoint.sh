#!/bin/sh

set -e

APP_ROOT=/var/www/html
AUTO_FIX_PERMISSIONS=${AUTO_FIX_PERMISSIONS:-true}

umask 0002

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
    chown www-data:www-data "$APP_ROOT/logs"
    chmod 2775 "$APP_ROOT/logs"
fi

# Run migrations and seeders (idempotent). Retry a few times to wait for the database to be ready.
MAX_RETRIES=5
RETRY_DELAY=5
i=0
until php artisan migrate --force --seed --ansi 2>&1; do
    i=$((i+1))
    if [ "$i" -ge "$MAX_RETRIES" ]; then
        echo "Migrations failed after $i attempts; continuing startup (containers may be unhealthy)"
        break
    fi
    echo "Waiting for database to be ready... retry #$i in ${RETRY_DELAY}s"
    sleep $RETRY_DELAY
done

TODAYS_CENTRALIZED_LOG="$APP_ROOT/logs/centralized-$(date +%Y-%m-%d).log"
if [ -f "$TODAYS_CENTRALIZED_LOG" ]; then
    chown www-data:www-data "$TODAYS_CENTRALIZED_LOG"
    chmod 664 "$TODAYS_CENTRALIZED_LOG"
fi

# Mark as migrated so other tooling can check this file if needed
touch "$APP_ROOT/storage/framework/.migrated"

exec php-fpm
