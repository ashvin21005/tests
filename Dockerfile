FROM node:22-bookworm-slim AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN npm run build

FROM composer:2 AS dependencies

WORKDIR /app

COPY composer.json composer.lock ./
COPY app ./app
COPY database/factories ./database/factories
COPY database/seeders ./database/seeders
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends gettext-base libonig-dev libxml2-dev \
    && docker-php-ext-install mbstring pdo_mysql xml \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite

WORKDIR /var/www/html

COPY . .
COPY --from=dependencies /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY docker/apache-ports.conf /etc/apache2/ports.conf.template
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf.template
COPY docker/entrypoint.sh /usr/local/bin/railway-entrypoint

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && php artisan package:discover --ansi \
    && chmod +x /usr/local/bin/railway-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=mysql \
    SESSION_DRIVER=database \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    LOG_CHANNEL=stderr

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/railway-entrypoint"]
