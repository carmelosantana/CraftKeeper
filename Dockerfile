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

# Runtime defaults for optional subsystems.
#
# BROADCAST_CONNECTION: Laravel's own default (config/broadcasting.php) is
# `reverb`. routes/channels.php calls Broadcast::channel() during boot, so
# with no Reverb credentials configured the Reverb driver constructs Pusher
# with a null auth key and throws a TypeError *before any route runs* —
# every request, including /up, returns 500. Realtime streaming is optional
# in CraftKeeper by design, so the container defaults to the null-ish `log`
# driver (matching .env.example) and degrades gracefully instead of failing
# closed. Operators who want live console/operation streaming set
# BROADCAST_CONNECTION=reverb plus the REVERB_* credentials, which overrides
# this default like any other environment variable.
ENV BROADCAST_CONNECTION=log

# Where the application publishes events to its OWN Reverb process. These
# describe an internal hop, not anything an operator should have to know:
# docker/supervisor/supervisord.conf starts Reverb with a hard-coded
# `--host=127.0.0.1 --port=8081`, and these must match it.
#
# Laravel's own defaults are no host at all, port 443, scheme https, which
# produced `Unable to parse URI: https://:443` on every broadcast — the
# websocket connected and the channel authorised, then nothing was ever
# delivered, which reads exactly like a client bug. Defaulting them here
# means enabling realtime is only BROADCAST_CONNECTION=reverb plus the three
# REVERB_APP_* credentials.
#
# Not to be confused with the browser-facing endpoint: the frontend connects
# to the page's own origin, because Nginx proxies the Pusher protocol's
# `/app` path through to this same port. See resources/js/lib/echo.ts.
ENV REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8081 \
    REVERB_SCHEME=http

WORKDIR /var/www/html

COPY --from=build --chown=craftkeeper:craftkeeper /app ./

EXPOSE 8080

# Deliberately NOT `USER craftkeeper`. The entrypoint starts as root, adapts
# this container's group membership to whatever already owns the mounted
# Minecraft volume, and then drops to craftkeeper with `setpriv` and re-execs
# itself — see docker/entrypoint.sh. Every application process (Supervisor,
# PHP-FPM, Nginx, the queue worker, the scheduler, Reverb) still runs as
# craftkeeper; root exists only for the few lines before that drop, and is
# never regained.
#
# This is what lets CraftKeeper run beside an arbitrary Minecraft server
# image without the operator hand-writing `group_add` and a `chmod` — the
# server image picks its own uid/gid, so matching it has to happen at runtime.
#
# Running the container with an explicit `--user`/`user:` still works: the
# entrypoint sees it is not root, skips the adaptation entirely, and proceeds
# unprivileged exactly as before.

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD curl --fail --silent http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
