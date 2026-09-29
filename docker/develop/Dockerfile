FROM ghcr.io/mintopia/php-frankenphp-base:8.4.10

RUN apk add --update nodejs npm && \
    npm install -g chokidar && \
    install-php-extensions xdebug

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/

COPY docker/develop/php-dev.ini /etc/frankenphp/php.ini
COPY docker/Caddyfile /
ENV XDEBUG_MODE=off

ENTRYPOINT ["php", "artisan", "octane:frankenphp", "--max-requests=1", "--watch", "--poll", "--admin-host=0.0.0.0", "--port=80", "--caddyfile=/Caddyfile"]
