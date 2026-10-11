# PHP-FPM image for the Laravel API.
# Multi-arch: official php:*-fpm-bookworm supports linux/arm64 (Oracle Ampere) and amd64.
ARG PHP_BASE=php:8.4-fpm-bookworm
FROM ${PHP_BASE} AS base

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_mysql bcmath intl zip gd opcache pcntl redis @composer \
    && apt-get update && apt-get install -y --no-install-recommends default-mysql-client curl procps \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/docker/php/php.ini /usr/local/etc/php/conf.d/zz-growth-planet.ini

WORKDIR /var/www/backend

FROM base AS dev
ENV COMPOSER_HOME=/tmp/composer

# ---------------------------------------------------------------------------
# Production: application code baked into the image (no bind-mount of source).
# ---------------------------------------------------------------------------
FROM base AS prod

COPY deploy/docker/php/php.prod.ini /usr/local/etc/php/conf.d/zz-growth-planet-prod.ini
COPY deploy/docker/php/docker-entrypoint.sh /usr/local/bin/growth-planet-entrypoint
RUN chmod +x /usr/local/bin/growth-planet-entrypoint

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1

COPY backend/composer.json backend/composer.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --prefer-dist \
      --no-interaction \
      --optimize-autoloader

COPY backend/ ./

# Host bootstrap/cache may reference require-dev providers (e.g. Pail); rebuild discovery for --no-dev.
RUN rm -f bootstrap/cache/*.php \
    && mkdir -p \
      storage/app/public \
      storage/app/private \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/logs \
      bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

# php-fpm must start as root so it can bind and drop privileges to www-data.
ENTRYPOINT ["growth-planet-entrypoint"]
CMD ["php-fpm", "-F"]
