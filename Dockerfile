FROM php:8.2-cli-alpine

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev linux-headers \
    && apk add --no-cache wget \
    && docker-php-ext-install pdo_sqlite sockets \
    && apk del .build-deps \
    && apk add --no-cache sqlite-libs

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-ansi

COPY config ./config
COPY public ./public
COPY src ./src

RUN mkdir -p /var/www/data/keys && chown -R www-data:www-data /var/www/data

ENV DB_PATH=/var/www/data/activity.db

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/health || exit 1

USER www-data

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
