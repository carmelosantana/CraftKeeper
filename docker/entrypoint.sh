#!/usr/bin/env bash
#
# CraftKeeper container entrypoint.
#
# Two phases:
#
#   1. If started as root, adapt this container's own identity to whatever
#      already owns the Minecraft volume, then permanently drop to the
#      unprivileged craftkeeper user and re-exec. Nothing after this point
#      ever runs as root.
#   2. As craftkeeper: prepare /data, migrate, and exec the requested
#      command as PID 1.
#
# Phase 1 exists because CraftKeeper's stated operating model is running
# beside an existing Minecraft server container — primarily
# TheRemote/Legendary-Java-Minecraft-Geyser-Floodgate — sharing one volume.
# Those images pick their own uid/gid (Legendary's `useradd -r` lands on 999
# but is not pinned), so a fixed uid here would read every file fine and fail
# every write: the volume's files are group-writable, and we would simply not
# be in that group. Making the operator hand-write `group_add` and a `chmod`
# turned a solvable startup detail into a runbook, and a silent one — reads
# work, so the UI looks healthy right up until the first approved change
# fails to land.
#
# What this does NOT do: it never chowns /minecraft and never takes ownership
# of anything (the V1 plan forbids that, and it is the right constraint — the
# server's files belong to the server). It changes *our* group membership to
# match what is already there.

set -euo pipefail

# Exported, not merely assigned — same reasoning as DATA_ROOT/DB_DATABASE
# below. /minecraft is this image's declared mount point for the server
# volume and the path this script adapts permissions on, but
# config/craftkeeper.php falls back to storage_path('craftkeeper/minecraft')
# when MINECRAFT_ROOT is absent. Without the export the two disagree: the
# entrypoint joins the right group and fixes permissions on /minecraft while
# the application looks somewhere else entirely and reports "The Minecraft
# root is unavailable" on a correctly mounted, fully working volume.
export MINECRAFT_ROOT="${MINECRAFT_ROOT:-/minecraft}"
CRAFTKEEPER_USER="craftkeeper"

log() { echo "[entrypoint] $*"; }

if [ "$(id -u)" = '0' ]; then
    ck_uid="$(id -u "$CRAFTKEEPER_USER")"
    ck_gid="$(id -g "$CRAFTKEEPER_USER")"

    if [ -d "$MINECRAFT_ROOT" ]; then
        mc_uid="$(stat -c '%u' "$MINECRAFT_ROOT")"
        mc_gid="$(stat -c '%g' "$MINECRAFT_ROOT")"

        # Join the volume's owning group so the group-write bits that server
        # images already set (Legendary ships files 664 / dirs 775) apply to
        # us. This is the whole fix for the uid mismatch.
        if [ "$mc_gid" != "0" ] && [ "$mc_gid" != "$ck_gid" ]; then
            host_group="$(getent group "$mc_gid" | cut -d: -f1 || true)"
            if [ -z "$host_group" ]; then
                host_group="minecraft-host"
                groupadd --gid "$mc_gid" "$host_group" 2>/dev/null || true
            fi
            if usermod --append --groups "$host_group" "$CRAFTKEEPER_USER" 2>/dev/null; then
                log "joined group ${host_group} (gid ${mc_gid}) — matches ${MINECRAFT_ROOT} (uid ${mc_uid})"
            else
                log "WARNING: could not join gid ${mc_gid}; writes to ${MINECRAFT_ROOT} may fail"
            fi
        fi

        # Group membership alone is not always enough. Atomic writes create a
        # temporary file in the *same directory* and rename() over the target,
        # which needs write permission on the directory itself — not just on
        # the file. Legendary creates its subdirectories 775 but leaves the
        # volume root 755, so without this, edits to config/ and plugins/
        # succeed while server.properties (which lives at the root, and is
        # where RCON is configured) silently fails. That split is far more
        # confusing than a clean failure.
        #
        # Scope: only ever adds the group-write bit, only to directories, and
        # only to directories already owned by a group we now belong to. Never
        # changes ownership, never touches file contents, never widens
        # world permissions. Set CRAFTKEEPER_ADAPT_PERMISSIONS=off to disable
        # and have CraftKeeper report the condition instead of fixing it.
        if [ "${CRAFTKEEPER_ADAPT_PERMISSIONS:-on}" != "off" ]; then
            for dir in "$MINECRAFT_ROOT" "$MINECRAFT_ROOT"/*/; do
                [ -d "$dir" ] || continue
                dir_gid="$(stat -c '%g' "$dir")"
                dir_mode="$(stat -c '%a' "$dir")"
                # only if we are in that group and it is not already group-writable
                if [ "$dir_gid" = "$mc_gid" ] && [ "$((0$dir_mode & 020))" -eq 0 ]; then
                    if chmod g+w "$dir" 2>/dev/null; then
                        log "added group-write to ${dir} (was ${dir_mode}) so atomic writes can land"
                    fi
                fi
            done
        fi
    else
        log "note: ${MINECRAFT_ROOT} is not present; skipping volume adaptation"
    fi

    # CraftKeeper's OWN state directory. Unlike /minecraft this one is ours to
    # create and own outright (the V1 plan requires the image to create /data),
    # and doing it here means a root-owned bind mount works as well as a named
    # volume — otherwise the unprivileged half below cannot write to it.
    data_root="${DATA_ROOT:-/data}"
    mkdir -p "$data_root"
    if [ "$(stat -c '%u' "$data_root")" != "$ck_uid" ]; then
        chown "$ck_uid:$ck_gid" "$data_root" 2>/dev/null \
            && log "took ownership of ${data_root} (CraftKeeper's own state)" \
            || log "WARNING: ${data_root} is not writable by ${CRAFTKEEPER_USER}"
    fi

    # Hand the container's stdout/stderr to the user we are about to become.
    # Docker creates PID 1's stdout owned by the image's configured user —
    # now root — and supervisord redirects every program's output to
    # /dev/stdout, which is a symlink to /proc/self/fd/1. After dropping
    # privileges those reopens fail with EACCES and supervisord cannot spawn
    # a single program ("unknown error making dispatchers"), so the container
    # comes up with nothing but supervisord running and no listener at all.
    # We still hold those descriptors here, so chown them while we can.
    #
    # Deliberately NOT `chown ... /proc/self/fd/2 2>/dev/null`. Redirecting
    # stderr rebinds fd 2 for the duration of that very command, so
    # /proc/self/fd/2 resolves to /dev/null and chown retargets /dev/null
    # while the real stderr pipe stays root-owned — leaving supervisord
    # unable to open /dev/stderr. Any error here is reported, not swallowed.
    chown "$ck_uid:$ck_gid" /proc/self/fd/1 /proc/self/fd/2 \
        || log "WARNING: could not hand over stdout/stderr; program logs may not reach docker logs"

    # Drop privileges for good. --init-groups reloads supplementary groups
    # from /etc/group, which is what picks up the membership added above.
    log "dropping to ${CRAFTKEEPER_USER} (uid ${ck_uid})"
    exec setpriv --reuid="$ck_uid" --regid="$ck_gid" --init-groups "$0" "$@"
fi

# ---------------------------------------------------------------------------
# Everything below runs unprivileged.
# ---------------------------------------------------------------------------
#
# Bootstrap (directory/database preparation and migration) only runs ahead
# of the actual application command (Supervisor). Any other command — a
# one-off `php -v`, `php artisan tinker`, a shell for debugging, etc. — is
# exec'd directly so ad hoc invocations aren't forced through a full boot
# sequence that assumes the compose-provided DATA_ROOT/DB_DATABASE
# environment.
if [ "${1:-}" = "/usr/bin/supervisord" ]; then
    # These MUST be exported, not just assigned. A bare assignment creates a
    # shell-local variable that PHP never sees, and the two sides then
    # disagree about where the database lives:
    #
    #   entrypoint fallback:  $DATA_ROOT/database.sqlite      -> /data/database.sqlite
    #   Laravel fallback:     storage_path('craftkeeper')/... -> /var/www/html/storage/craftkeeper/database.sqlite
    #
    # (see config/database.php's sqlite.database and config/craftkeeper.php's
    # data_root, which both fall back to storage_path when DATA_ROOT is absent)
    #
    # So the entrypoint would create and migrate one file while the running
    # application looked for another, and every request failed with
    # "Database file ... does not exist" — but ONLY when DATA_ROOT/DB_DATABASE
    # were absent from the environment. compose.example.yml sets both, which
    # is why this stayed hidden until the release pipeline's smoke test ran
    # the image with no configuration at all.
    #
    # Exporting makes the entrypoint's defaults authoritative for PHP too
    # (php-fpm's pool sets `clear_env = no`, so workers inherit them).
    export DATA_ROOT="${DATA_ROOT:-/data}"
    mkdir -p "$DATA_ROOT"

    export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
    export DB_DATABASE="${DB_DATABASE:-$DATA_ROOT/database.sqlite}"

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
