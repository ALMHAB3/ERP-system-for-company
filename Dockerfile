# syntax=docker/dockerfile:1
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache postgresql-dev libzip-dev icu-dev \
    && docker-php-ext-install pdo_pgsql opcache intl pcntl \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-erp.ini
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]
