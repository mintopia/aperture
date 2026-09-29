FROM ghcr.io/mintopia/php-frankenphp-base:8.4.10 AS composer

WORKDIR /app/
ENV HOME=/tmp
RUN mkdir -p /app
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY . /app/
COPY docker/production/production.env /app/.env

RUN composer install \
        --no-dev \
        --no-progress \
        --optimize-autoloader \
        --prefer-dist \
        --ignore-platform-reqs

FROM node:24-slim AS npm

WORKDIR /app/
ENV HOME=/tmp
RUN mkdir -p /app
COPY --from=composer /app/ /app

RUN npm install -g npm@12

RUN npm install --production=false && \
    npm run build

FROM ghcr.io/mintopia/php-frankenphp-base:8.4.10
LABEL org.opencontainers.image.authors="jess@mintopia.net"

COPY --from=composer /app /app
COPY --from=npm /app/public/build /app/public/build
COPY docker/production/entrypoint.sh /entrypoint.sh
RUN apk add --no-cache su-exec && \
    chmod +x /entrypoint.sh && \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/memory_limit.ini && \
    setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp && \
    cp /app/vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php /app/public/frankenphp-worker.php && \
    mkdir -p /data/caddy /config/caddy /app/storage /app/bootstrap/cache && \
    chown -R www-data:www-data /data/caddy /config/caddy /app/storage /app/bootstrap/cache

COPY docker/Caddyfile /

ENV PUID=82 \
    PGID=82

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php",  "artisan",  "octane:frankenphp", "--caddyfile=/Caddyfile", "--admin-host=0.0.0.0", "--port=80"]
