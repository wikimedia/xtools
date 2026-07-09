# syntax=docker/dockerfile:1

# Build the front-end assets with Encore. Node version pinned to .nvmrc.
FROM node:20.12.2-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# PHP-FPM runtime shared by the app and api services. This `base` stage is the
# dev target: it has the extensions and composer but no application code, since
# dev bind-mounts the working tree over /var/www.
FROM php:8.2-fpm-bookworm AS base
# ext-curl ships enabled in the official image; intl and pdo_mysql are the two
# we have to build (intl needs libicu, pdo_mysql is bundled with PHP sources).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        unzip \
    && docker-php-ext-install intl pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer
WORKDIR /var/www
COPY .docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# Production image: bake the code, install prod-only deps, pull in built assets.
FROM base AS prod
ENV APP_ENV=prod \
    APP_DEBUG=0
COPY . /var/www
COPY --from=assets /app/public/build /var/www/public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R www-data:www-data var
USER www-data
