# Architecture / Bootstrap Decisions

## Task 1 — Bootstrap Laravel, Quality Gates, and License

**Dependency resolution:** All pinned capability packages in the Task 1 brief
resolved cleanly on PHP 8.4.23 / Composer 2.10.1 at (or above) their pinned
constraints — no fallback to a looser version was required:

| Package | Pinned constraint | Resolved version |
|---|---|---|
| `laravel/mcp` | `^0.7` | `v0.7.2` |
| `carmelosantana/php-agents` | `^0.15` | `v0.15.0` |
| `laravel/fortify` | (unpinned) | `v1.37.2` |
| `laravel/sanctum` | (unpinned) | `v4.3.2` |
| `laravel/passport` | (unpinned) | `v13.7.5` |
| `laravel/reverb` | (unpinned) | `v1.10.2` |
| `symfony/yaml` | (unpinned) | `v8.1.x` |
| `yosymfony/toml` | (unpinned) | `v1.0.4` |
| `larastan/larastan` (dev) | (unpinned) | `^3.10` (starter kit shipped `^3.9`; re-running `composer require --dev larastan/larastan` bumped the constraint to the latest compatible release) |

No pinned constraint required weakening. No package in the resolved graph
requires Docker socket access; `laravel/sail` ships with the starter kit as
optional local-Docker tooling but is not invoked by any Composer/npm script
in the verification gates.

**Environment:** Built and verified on PHP 8.4.23, Composer 2.10.1, Node
26.4.0, npm 11.17.0. The plan/CI target Node 22 and PHP 8.4; Node 26 was
used locally per the task's ambiguity resolution and no incompatibility was
observed.

**`APP_NAME` default:** `config/app.php`'s `'name'` fallback was changed
from `'Laravel'` to `'CraftKeeper'` (in addition to setting `APP_NAME` in
`.env`/`.env.example`) so that the root route reliably renders "CraftKeeper"
regardless of whether a local `.env` is present — `.env` is git-ignored, so
relying on it alone would make `BootTest` environment-dependent.

**`composer.json` identity:** `name` was changed from the starter kit's
`laravel/react-starter-kit` to `craftkeeper/craftkeeper`, and `license` from
`MIT` to `AGPL-3.0-or-later`, matching the product identity and licensing
established in the V1 plan.

**Quality scripts:** `composer.json`'s `test` script was replaced with the
exact sequence from the Task 1 brief (`config:clear`, `artisan test`,
`phpstan analyse --memory-limit=1G`, `pint --test`). The starter kit's
pre-existing `lint`, `lint:check`, `types:check`, and `ci:check` scripts
were left in place (unused by the four required gates but kept so the
starter kit's `.github/workflows/tests.yml`, which calls `composer
ci:check`, keeps working). `package.json` gained the brief's required
`test`, `typecheck`, and `e2e` scripts; the pre-existing `types:check` was
kept alongside `typecheck` (identical command) for the same CI-workflow
compatibility reason.

**Boot test greenness:** `npm run test` requires at least one passing
Vitest test to exit 0 (Vitest exits non-zero on an empty test run). A small
infrastructure-only smoke test was added at `resources/js/lib/utils.test.ts`
covering the existing `cn()` class-name helper — it exercises the Vitest +
Testing Library + jsdom pipeline without building any new UI, which is out
of scope for Task 1 (Task 3).

**License text:** `LICENSE` is the unmodified GNU AGPL v3 license text
fetched verbatim from `https://www.gnu.org/licenses/agpl-3.0.txt`.

## Task 2 — Docker, Dokploy, and Process Runtime

**Reconciling the default `/up` route:** The starter kit's
`bootstrap/app.php` registered Laravel's built-in health route via
`->withRouting(health: '/up')`, which returns a bare `{"status":"up"}`/HTML
page. That parameter was removed so `App\Http\Controllers\HealthController`
(routed in `routes/web.php`) is the only `/up` handler — verified with
`php artisan route:list`, which shows exactly one `GET|HEAD /up` route. The
default route's maintenance-mode bypass would otherwise have been lost, so
`bootstrap/app.php` now calls
`Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::except('/up')`
directly, preserving the property that health checks keep passing during
`php artisan down`.

**`config/craftkeeper.php` (new, not in the brief's file list):** The brief
lists `Modify: config/database.php` but not a new config file. One was
added anyway because Larastan's `noEnvCallsOutsideOfConfig` rule (enabled
by default in this repo's `phpstan.neon`) forbids calling `env()` outside
`config/*.php`, and `HealthController`'s data-directory check needs a
configurable root path. `config/craftkeeper.php` exposes
`data_root => env('DATA_ROOT', storage_path('craftkeeper'))`; the container
sets `DATA_ROOT=/data`, local/test environments fall back to a path under
Laravel's own storage directory. `config/database.php`'s sqlite connection
now defaults `database` to `env('DATA_ROOT', storage_path('craftkeeper')).'/database.sqlite'`
instead of `database_path('database.sqlite')`, so CraftKeeper's own state
consistently lives under the data root rather than inside the app
directory. The pre-existing local dev database at `database/database.sqlite`
is now unused (it was already git-ignored); local development was
re-pointed to `storage/craftkeeper/database.sqlite` and migrated, and
`/storage/craftkeeper` was added to `.gitignore`.

**Health check design:** Both `/up` checks are real, not tautological.
`checkDatabase()` calls `DB::connection()->getPdo()`, which lazily resolves
the *current default* connection — a genuinely broken connection (verified
in `tests/Feature/HealthTest.php` by pointing `database.default` at a
sqlite path that doesn't exist, which throws
`SQLiteDatabaseDoesNotExistException`) makes the endpoint return HTTP 503
with `checks.database.status === 'error'`. `checkDataDirectory()` probes
`config('craftkeeper.data_root')`, self-heals a missing directory with
`File::makeDirectory($path, 0755, true, true)` (the `$force = true` silences
the underlying `mkdir()` warning so an unwritable parent reports through the
JSON status instead of surfacing a raw PHP warning), and reports `error`
when the path still isn't a writable directory afterward (verified with a
`0500`-permission parent directory in the test suite). This self-healing
behavior is also why local `php artisan test` and `php artisan serve` work
without any container-only setup: the directory is created on first health
check.

**Entrypoint bootstrap is conditional on the actual app command:**
`docker/entrypoint.sh` only runs its `/data` + sqlite-file + `artisan
migrate` bootstrap when the command being exec'd is
`/usr/bin/supervisord` (i.e., the image's own `CMD`, real container boot).
Any other command — `php -v`, `php artisan tinker`, an interactive shell —
is exec'd directly. This was discovered empirically: the brief's Step 4
verification command `docker run --rm craftkeeper:test php -v` doesn't set
`DATA_ROOT`/`DB_DATABASE`, and an unconditional bootstrap crashed on
`SQLiteDatabaseDoesNotExistException` because the entrypoint's bash default
(`DATA_ROOT:-/data`) and the PHP-side default
(`storage_path('craftkeeper')`, meant for local, non-Docker use) disagree
when neither is set. In real Dokploy/Compose deployments this never
surfaces because `compose.example.yml` always sets `DATA_ROOT=/data` and
`DB_DATABASE=/data/database.sqlite` explicitly.

**Base images and process wiring:** Both Docker stages use Debian
`bookworm` (`php:8.4-cli-bookworm` for the combined composer-install +
`npm run build` stage, `php:8.4-fpm-bookworm` for the runtime stage) rather
than Alpine, to keep `apt`-based extension installation (`pdo_sqlite`,
`mbstring`, `bcmath`, `intl`, `zip`, `pcntl`, `exif`, `gd`, `sodium`,
`opcache`) straightforward and consistent between the two stages. The asset
build is a single combined stage (not split PHP-only/Node-only stages)
because `@laravel/vite-plugin-wayfinder` shells out to `php artisan
wayfinder:generate` during `npm run build`, so the frontend build needs a
fully bootable Laravel application (`composer install` already run)
alongside Node 22 — confirmed necessary by reading
`node_modules/@laravel/vite-plugin-wayfinder/dist/index.mjs`, which calls
`exec('php artisan wayfinder:generate ...')`. A fixed, non-secret dummy
`APP_KEY` plus array/sync/log-driver env vars are set only in that build
stage (mirroring `phpunit.xml`'s testing environment) so `composer
install`'s `post-autoload-dump` hook (`artisan package:discover`) and
`wayfinder:generate` can boot the framework without a real `.env`; none of
that stage's environment or filesystem state is copied into the runtime
image (Docker BuildKit does flag the dummy `APP_KEY` ENV with a generic
"SecretsUsedInArgOrEnv" lint warning — it is a fixed placeholder string,
not a real secret, and is confirmed absent from the final `runtime` stage).

The whole container runs as a non-root `craftkeeper` user (uid/gid 1000)
created in the runtime stage: Nginx's `user` directive is stripped from
`/etc/nginx/nginx.conf` (a root-only directive), its pid file is moved to
`/tmp/nginx.pid`, and `/var/lib/nginx`, `/var/log/nginx`, `/var/log/supervisor`,
and `/run/php` are chowned to `craftkeeper` at build time. PHP-FPM listens
on `127.0.0.1:9000` (TCP, not a unix socket, to avoid ordering/permission
dependencies on `/run/php` existing at container start). `/data` is created
and chowned to `craftkeeper` at build time and declared as a `VOLUME`, so a
fresh named volume (as `compose.example.yml` uses) inherits that ownership
on first mount — the standard Docker trick for non-root writable volumes.
`/minecraft` is never created, mkdir'd, or chowned anywhere in the
Dockerfile, entrypoint, or Supervisor config (grepped to confirm). Reverb
binds to `127.0.0.1:8081` only (`reverb:start --host=127.0.0.1
--port=8081`), never published as a container port; Nginx's `location /app`
proxies websocket upgrades to it. No file in this task references
`docker.sock` or `/var/run/docker.sock`.

**Real Docker verification performed:** `docker build -t craftkeeper:test .`
completed successfully (image size ~732MB, unoptimized — a reasonable
follow-up would be to drop `-dev` packages after `docker-php-ext-install` or
switch to Alpine, deferred as out of scope for V1). `docker compose -f
compose.example.yml config` renders valid, resolved YAML (with the expected
warning that `CRAFTKEEPER_APP_KEY` is unset, since the example intentionally
requires an operator-supplied secret). `docker run --rm craftkeeper:test php
-v` prints `PHP 8.4.23`. Beyond the brief's three commands, the image was
actually run end-to-end (`docker run -d -p 18080:8080 -e APP_KEY=... -e
DATA_ROOT=/data ...`): all five Supervisor programs (`php-fpm`, `nginx`,
`queue-worker`, `scheduler`, `reverb`) reached the `RUNNING` state, `GET
/up` through the published port returned real `200 {"status":"ok",...}`,
Docker's own `HEALTHCHECK` reported the container `healthy` after three
consecutive successful probes, `/minecraft` was confirmed absent inside the
running container, `/data` was confirmed owned by `craftkeeper:craftkeeper`,
and the only mount was the anonymous `/data` volume (no `docker.sock`).
