# syntax=docker/dockerfile:1

############################################
# 1) PHP dependencies (needed by the CSS build too: Flux styles live in vendor/)
############################################
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

############################################
# 2) Front-end assets (Vite)
############################################
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

############################################
# 3) Runtime: PHP-FPM + Nginx (serversideup/php), listens on :8080
############################################
FROM serversideup/php:8.3-fpm-nginx-bookworm

USER root

# bcmath/gmp: Web Push encryption · intl: number/date formatting
# postgresql-client-16: pg_dump for "php artisan finance:backup" (must match the server major version)
RUN install-php-extensions bcmath gmp intl \
    && apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 \
    && apt-get purge -y gnupg && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

ENV PHP_OPCACHE_ENABLE=1 \
    PHP_MEMORY_LIMIT=256M \
    PHP_DATE_TIMEZONE=UTC

COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

USER www-data

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction \
    && mkdir -p storage/backups storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
