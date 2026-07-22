# Installing CraftKeeper with Docker Compose

CraftKeeper ships as one container image
(`ghcr.io/carmelosantana/craftkeeper`) built from the `Dockerfile` in
this repository: PHP 8.4-FPM, Nginx, and Supervisor running one database
queue worker, `schedule:work`, the `server:watch` RCON health poll, and
Laravel Reverb, all as a non-root `craftkeeper` user (uid/gid `1000`). The image never creates, chowns, or
otherwise touches `/minecraft` — that is entirely your volume, owned
however your Minecraft server process expects. CraftKeeper also never
needs `/var/run/docker.sock` or any equivalent — it does not, and will
not, manage container lifecycle for itself or anything else.

## Prerequisites

- Docker Engine with the Compose v2 plugin (`docker compose version`).
- A running (or startable) Minecraft server whose files live in a Docker
  **named volume** — a bind mount also works, but a named volume is what
  `compose.example.yml` assumes and is what most Minecraft-server Compose
  stacks (e.g. `itzg/docker-minecraft-server`) already use.
- A reverse proxy terminating TLS in front of CraftKeeper if you're
  exposing it publicly (see `docs/installation/dokploy.md` if that proxy
  is Dokploy). CraftKeeper itself only ever speaks plain HTTP on `:8080`.

## 1. Choose the shared external volume

CraftKeeper and your Minecraft server container must mount the **same**
volume — CraftKeeper at `/minecraft`, your Minecraft server at whatever
path its own image expects (commonly `/data`). `compose.example.yml`
declares this volume as `external: true`, meaning **you create it
first**, outside of CraftKeeper's own compose file, in whichever compose
project or `docker volume create` command your Minecraft server stack
already uses:

```bash
docker volume create minecraft-data
```

Point both your Minecraft server's compose file and CraftKeeper's at the
same volume name. If your Minecraft stack already has a named volume,
reuse that name in `compose.example.yml`'s own `volumes:` section instead
of creating a second one — CraftKeeper does not require its own copy of
the world.

## 2. File UID/GID strategy

CraftKeeper's application processes run as uid/gid `1000` inside the
container (never root). For CraftKeeper to discover, preview, and write
configuration files under `/minecraft`, that uid needs read (and, for any
save, write) access to the volume's contents. In order of preference:

1. **Run your Minecraft server container as uid/gid `1000` too.** Many
   Minecraft server images (including `itzg/docker-minecraft-server` via
   its `UID`/`GID` environment variables) support this directly — if
   yours does, set it to `1000` and every file it creates is already
   readable/writable by CraftKeeper with no further steps.
2. **`chown` the volume once, out-of-band**, if your Minecraft server
   image insists on a different uid (or runs as root):
   ```bash
   docker run --rm -v minecraft-data:/minecraft alpine chown -R 1000:1000 /minecraft
   ```
   This was the exact fix required for this project's own integration
   test stack (`docker-compose.integration.yml`), where the seed
   container's default `root:root` ownership made every config-apply
   fail post-write verification — see `docs/architecture/decisions.md`
   (Task 20).
3. **Make the volume group-writable** (`chmod -R g+rwX`) and ensure both
   containers share a common gid, if you cannot control either
   container's uid directly.

Whichever you choose, verify it once after first bringing both
containers up:

```bash
docker compose exec craftkeeper sh -c "touch /minecraft/.craftkeeper-write-test && rm /minecraft/.craftkeeper-write-test && echo OK"
```

If that fails, CraftKeeper's own Overview/Configurations pages will also
show discovery as degraded with a permission-denied reason rather than a
silent empty list — this is a deliberate degraded state, not a crash (see
`docs/security/threat-model.md`).

## 3. Required `APP_KEY`

Laravel encrypts sessions and, more importantly for CraftKeeper, the
`secrets` table (RCON password, hosted-AI API key) using `APP_KEY`.
**Generate one before first boot and keep it** — losing it means losing
the ability to decrypt those stored secrets on restore (see
`docs/operations/recovery.md`).

```bash
export CRAFTKEEPER_APP_KEY="base64:$(openssl rand -base64 32)"
```

`compose.example.yml` reads this from the `CRAFTKEEPER_APP_KEY`
environment variable at `docker compose up` time — set it in your shell,
an `.env` file next to `compose.example.yml`, or your orchestrator's own
secret-injection mechanism. Do not bake it into the image or commit it to
version control.

## 4. Bring the stack up

```bash
docker compose -f compose.example.yml up -d
docker compose -f compose.example.yml ps
```

The container's `HEALTHCHECK` polls `GET /up` every 30 seconds; wait for
it to report `healthy` before continuing. `docker/entrypoint.sh` runs
`php artisan migrate --force` against a fresh `/data` on every boot
(idempotent on a database that already has the migrations applied), so
the very first boot against an empty `craftkeeper_data` volume creates
the SQLite schema automatically — no manual migration step is required.

## 5. First-run admin

Open the application at your configured `APP_URL` (or
`http://localhost:8080` for a local test). You are redirected to
onboarding automatically until an administrator exists — the same
`InstallationState::isInstalled()` check gates the onboarding routes and
disables registration everywhere else the moment the first (and only)
admin account is created. Onboarding walks through: Welcome, the admin
account, a Minecraft directory check (this is where step 2's UID/GID
setup gets verified for real), optional RCON setup/test, optional AI
provider, optional analytics, and completion. Every optional step has
"Skip for now" — none of them block reaching a working application.

## Next steps

- [`docs/operations/rcon.md`](../operations/rcon.md) — enabling RCON on
  your Minecraft server and keeping the RCON port private.
- [`docs/operations/recovery.md`](../operations/recovery.md) — backups,
  restore, and support bundles.
- [`docs/operations/upgrades.md`](../operations/upgrades.md) — upgrading
  to a new image tag and rolling back by digest.
