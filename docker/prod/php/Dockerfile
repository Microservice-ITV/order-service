# ============================================================
# STAGE 1 — Build
# Install all dev tooling, Composer deps, and compile the app.
# ============================================================
FROM php:8.4-fpm AS build

# Install system packages + PHP extensions needed at build time
# (includes git/zip/curl which Composer requires)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        mbstring \
        opcache \
        bcmath \
        exif \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

# Pull the official Composer binary so we don't need to curl-install it
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy dependency manifests first for Docker layer caching
COPY src/composer.json src/composer.lock /var/www/html/
WORKDIR /var/www/html

# Install production dependencies only (no dev, no scripts yet)
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader --no-scripts

# Copy the full application source (vendor already exists from previous step)
COPY src/ /var/www/html/

# Regenerate autoloader with the full classmap for optimal performance
RUN composer dump-autoload --optimize

# ============================================================
# STAGE 2 — Runtime
# Minimal image containing only what is needed at runtime.
# ============================================================
FROM php:8.4-fpm

# Install runtime PHP extensions + procps (provides pgrep for healthcheck)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        procps \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        mbstring \
        opcache \
        bcmath \
        exif \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

# Copy the fully-built application from the build stage
COPY --from=build /var/www/html /var/www/html
WORKDIR /var/www/html

# Ensure Laravel runtime directories exist with correct ownership & permissions
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy the production entrypoint script
COPY docker/prod/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
