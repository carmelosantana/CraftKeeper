#!/usr/bin/env bash
#
# CraftKeeper container entrypoint.
#
# Prepares /data (never /minecraft) and migrates the database, then execs
# the requested command as PID 1. Runs as the non-root craftkeeper user;
# every step here must succeed without root.
#
# Bootstrap (directory/database preparation and migration) only runs ahead
# of the actual application command (Supervisor). Any other command — a
# one-off `php -v`, `php artisan tinker`, a shell for debugging, etc. — is
# exec'd directly so ad hoc invocations aren't forced through a full boot
# sequence that assumes the compose-provided DATA_ROOT/DB_DATABASE
# environment.
if [ "${1:-}" = "/usr/bin/supervisord" ]; then
    DATA_ROOT="${DATA_ROOT:-/data}"
    mkdir -p "$DATA_ROOT"

    DB_CONNECTION="${DB_CONNECTION:-sqlite}"
    DB_DATABASE="${DB_DATABASE:-$DATA_ROOT/database.sqlite}"

    if [ "$DB_CONNECTION" = "sqlite" ] && [ "$DB_DATABASE" != ":memory:" ] && [ ! -f "$DB_DATABASE" ]; then
        mkdir -p "$(dirname "$DB_DATABASE")"
        touch "$DB_DATABASE"
    fi

    # Task 18: laravel/passport's OAuth token signing keys. Generated once
    # and persisted on the container's storage volume (gitignored —
    # /storage/*.key — never committed); `php artisan passport:keys`
    # refuses to overwrite an existing pair without --force, so this guard
    # only exists to keep restart logs quiet, not for correctness.
    if [ ! -f storage/oauth-private.key ]; then
        php artisan passport:keys --no-interaction
    fi

    php artisan migrate --force --no-interaction
fi

exec "$@"
