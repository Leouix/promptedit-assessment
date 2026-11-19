# syntax=docker/dockerfile:1

# --- Frontend build ---
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY public public
COPY vite.config.js jsconfig.json ./
RUN npm run build

# --- PHP backend ---
FROM php:8.4-fpm-alpine

RUN apk add --no-cache libzip-dev icu-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-install pdo_sqlite bcmath intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
COPY --from=frontend /app/public/build /opt/public-build

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod +x docker/entrypoint.sh

USER www-data

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
