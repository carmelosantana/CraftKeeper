# syntax=docker/dockerfile:1

#################################################################
# Stage 1: build vendor/ and compiled frontend assets
#
# A single stage because Laravel Wayfinder's Vite plugin shells out to
# `php artisan wayfinder:generate` during `npm run build`, so the asset
# build needs a fully bootable PHP application (composer install already
# run) alongside Node, not just Node on its own.
#################################################################
FROM php:8.4-cli-bookworm AS build

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libsodium-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        mbstring \
        bcmath \
        intl \
        zip \
        pcntl \
        exif \
        gd \
        sodium \
        opcache \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

# Ephemeral build-only environment so `composer install`'s post-autoload-dump
# hook (`artisan package:discover`) and `npm run build`'s wayfinder:generate
# can boot the framework without a real .env, database, or secret. None of
# this is copied into the runtime stage below.
ENV APP_ENV=production \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    BROADCAST_CONNECTION=log \
    LOG_CHANNEL=stack

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

RUN npm ci && npm run build

#################################################################
# Stage 2: runtime image — PHP-FPM + Nginx + Supervisor, non-root
#################################################################
FROM php:8.4-fpm-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libsodium-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        mbstring \
        bcmath \
        intl \
        zip \
        pcntl \
        exif \
        gd \
        sodium \
        opcache \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

# Non-root application user. Application processes (PHP-FPM, Nginx, the
# queue worker, the scheduler, Reverb) all run as this user — nothing in
# this image runs as root at container runtime.
RUN groupadd --gid 1000 craftkeeper \
    && useradd --uid 1000 --gid craftkeeper --home-dir /var/www/html --shell /usr/sbin/nologin --no-create-home craftkeeper

# CraftKeeper's own state (SQLite database, future snapshots/audit data)
# lives under /data. Created and owned here so a fresh named volume mounted
# at /data inherits this ownership on first run. /minecraft is intentionally
# never created or chowned here: it is a bind/volume mount the operator
# supplies, owned by whatever the Minecraft server process expects.
RUN mkdir -p /data && chown craftkeeper:craftkeeper /data
VOLUME /data

# Nginx and Supervisor runtime directories writable by the non-root user.
RUN mkdir -p /var/log/supervisor /run/php \
    && chown -R craftkeeper:craftkeeper \
        /var/lib/nginx \
        /var/log/nginx \
        /var/log/supervisor \
        /run/php \
    && sed -i '/^user /d' /etc/nginx/nginx.conf \
    && sed -i 's#^pid .*#pid /tmp/nginx.pid;#' /etc/nginx/nginx.conf \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

COPY docker/nginx/default.conf /etc/nginx/conf.d/craftkeeper.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Task 20: upload/body-size (and memory_limit) overrides — see that
# file's own comments for why this is required at all (no php.ini of any
# kind was copied into this image before now, leaving PHP's tiny
# compiled-in defaults in place) and why its values are kept in lockstep
# with docker/nginx/default.conf's client_max_body_size.
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-craftkeeper-uploads.ini

# PHP-FPM pool: run as the non-root user and listen on the loopback TCP
# port Nginx proxies fastcgi requests to.
RUN { \
        echo '[www]'; \
        echo 'user = craftkeeper'; \
        echo 'group = craftkeeper'; \
        echo 'listen = 127.0.0.1:9000'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 10'; \
        echo 'pm.start_servers = 2'; \
        echo 'pm.min_spare_servers = 1'; \
        echo 'pm.max_spare_servers = 5'; \
        echo 'clear_env = no'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

COPY --from=build --chown=craftkeeper:craftkeeper /app ./

EXPOSE 8080

USER craftkeeper

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD curl --fail --silent http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
