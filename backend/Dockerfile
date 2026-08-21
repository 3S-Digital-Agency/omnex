# OMNEX backend — production image (Laravel 12, PHP 8.3 FPM)
# Multi-stage: composer deps + laravel optimizations, then a slim runtime.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --optimize-autoloader --no-scripts

FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache libpq postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del postgresql-dev

WORKDIR /var/www/html

# Application code
COPY . .

# Vendors (no dev deps) built in the first stage
COPY --from=vendor /app/vendor ./vendor

# Laravel runtime optimizations for production
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Non-root + read-only-ish runtime (writable dirs only)
USER www-data

EXPOSE 9000

CMD ["php-fpm"]
