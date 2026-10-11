# PHP-FPM image for the Laravel API (development target).
ARG PHP_BASE=php:8.4-fpm-bookworm
FROM ${PHP_BASE} AS base

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_mysql bcmath intl zip gd opcache pcntl redis @composer \
    && apt-get update && apt-get install -y --no-install-recommends default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/docker/php/php.ini /usr/local/etc/php/conf.d/zz-growth-planet.ini

WORKDIR /var/www/backend

FROM base AS dev
ENV COMPOSER_HOME=/tmp/composer
