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

## Task 3 — Design System and Responsive Application Shell

**Tailwind v4 token wiring — CSS custom properties, not a second
`tailwind.config.ts`:** The task brief lists `Modify: tailwind.config.ts`,
but Tasks 1–2 already set up Tailwind CSS 4 in its CSS-first mode (empty
`"config": ""` in `components.json`, no `tailwind.config.ts` file at all —
`resources/css/app.css`'s `@theme` block plus plain `:root`/`.dark`
selectors are the only theme configuration). Introducing a
`tailwind.config.ts` now would fight that existing setup rather than
extend it. Instead, every key in `Design/handoff/design-tokens.json` is
mapped 1:1 to a `--ck-*` custom property in `resources/css/app.css`:
neutrals/semantic colors/accent are declared per `[data-theme='dark'|
'light']` and layered with `[data-accent='terracotta'|'emerald'|'slate'|
'bronze']` (8 theme×accent combinations plus the dark/terracotta
`:root` default); provenance/dataViz/syntax/typography/spacing/radius/
layout/motion tokens are theme-independent and declared once on `:root`
so they inherit into every subtree regardless of where `data-theme` is
set. A second, separate bridge block (`[data-theme] { --background:
var(--ck-bg); ... }`) re-points the *existing* shadcn/ui semantic
variables (already consumed by every primitive in
`resources/js/components/ui`) at the new `--ck-*` tokens, scoped so it
never touches the un-scoped `:root`/`.dark` palette the pre-CraftKeeper
starter pages (dashboard/auth/settings) still use. This is how shadcn
primitives (Dialog, Sheet, the new Command palette, Button, Badge, ...)
re-theme for free with zero duplicated component styles.

**Theme/accent state lives on `<html>`, not just the AppShell root:**
`data-theme`/`data-accent` are set on the AppShell's own root `<div>` for
the normal render tree, but Sheet/Dialog/DropdownMenu (Radix primitives
used for the mobile nav drawer, CommandPalette, and admin menu) render
their content into a portal appended directly to `document.body`, which
is *outside* that div in the real DOM. CSS custom properties only
cascade down the DOM tree, so `resources/js/hooks/use-ck-theme.tsx`'s
`CkThemeProvider` also mirrors `data-theme`/`data-accent` onto
`document.documentElement` in a `useEffect` (cleaned up on unmount) —
otherwise switching to light theme or a non-default accent would leave
every portaled surface stuck on the dark/terracotta defaults.

**Fonts — self-hosted via `@fontsource`, not Google Fonts CDN, and not
the pre-existing `bunny()` provider:** `laravel-vite-plugin`'s `fonts`
integration (already used for the starter kit's Instrument Sans) exposes
two provider strategies: `bunny()`, which fetches WOFF2 files from
Bunny Fonts' CDN *at build time*, and `fontsource()`, which resolves
fonts from a locally installed `@fontsource/*` npm package with no
network call at all, build-time or runtime. `@fontsource/hanken-grotesk`
and `@fontsource/jetbrains-mono` (plain, non-variable packages — the
resolver expects discrete per-weight files, which is what the non-variable
packages ship) were installed and wired via `fontsource(...)` in
`vite.config.ts`, replacing the `bunny('Instrument Sans', ...)` call
entirely (Hanken Grotesk is now the site-wide `--font-sans`, matching the
V1 plan's "Hanken Grotesk is the UI font" — there is no remaining
Instrument Sans usage). `display: 'swap'` is passed explicitly for both;
`optimizedFallbacks: false` skips the optional `fontaine` package (a
purely cosmetic layout-shift optimization — YAGNI). `npm run build`
confirms the fonts are emitted as local files under `public/build/assets/`
(e.g. `hanken-grotesk-400-normal-*.woff2`) with zero `fonts.googleapis.com`
/`fonts.gstatic.com` references anywhere in the built output — this
matters ahead of a later task's strict CSP.

**`--ck-text-3` is not AA-safe as body text outside `--ck-bg`:**
`design-tokens.json`'s own notes claim "text-3 ~4.6:1 (WCAG 2.2 AA)", and
the mockups render it as literal small/muted body text throughout. Axe
(`@axe-core/playwright`, run against real Chromium) measured `--ck-text-3`
(`#877f72` dark) at only ~4.1:1 against `--ck-surface` (`#23201c`) and
~3.5:1 against `--ck-elevated` (`#302b26`) — both below the 4.5:1
normal-text AA threshold; only against the page's own darkest background,
`--ck-bg`, does it reach the claimed ratio. Every place in
`AppShell.tsx`/`DesignSystem.tsx` that rendered real (non-decorative)
text in `--ck-text-3` on a `--surface`/`--surface-2`/`--elevated`
background was changed to `--ck-text-2` (~7:1, holds AA everywhere);
`--ck-text-3` is kept only for decorative/border use (nav-icon outlines,
etc.) where axe's color-contrast rule doesn't apply. The Design Kit's own
"text on surface" swatch (`Card` background is `--ck-surface`) now shows
the `text-3` row as a small color chip next to `--ck-text-2`-colored
label copy instead of literally rendering body text in the failing color,
so the swatch still documents the true hex value without failing AA. This
is a deliberate, tested deviation from the mockup's literal styling in
favor of the plan's own non-negotiable ("Accessibility target is WCAG 2.2
AA").

**Playwright browser install: succeeded.** `npx playwright install
chromium` downloaded Chrome for Testing 149.0.7827.55 successfully in
this sandbox (`~/.cache/ms-playwright/chromium-1228`) — this is
important for every later UI task, since it confirms the sandbox has
outbound access to Playwright's CDN and e2e tests can actually run here,
not just be authored for CI.

**Playwright port 8000 (and other "well-known" ports) are intercepted by
a Kong gateway in this sandbox:** the first `npm run e2e` run failed
every test with `getByRole('heading', ...)` "element(s) not found";
the captured page HTML was `<h1>Kong Error</h1><p>Unauthorized.</p>`, not
the app. A plain `curl http://127.0.0.1:8000/up` reproduced the same
`401 {"message":"Unauthorized"}` with `Server: kong/2.8.1` and
`WWW-Authenticate: Basic realm="kong"` — with *no server process bound to
that port at all*, proving the interception happens before the request
reaches `php artisan serve`, at the sandbox's own network layer (port
8000 is evidently reserved/gated for some other purpose here). Switching
`localhost` → `127.0.0.1` did not help (same gateway). The fix was
picking an unreserved port: `playwright.config.ts`'s `webServer` now
boots `php artisan serve --host=127.0.0.1 --port=8123` (confirmed clean
with a direct `curl`) and `use.baseURL` matches. `APP_URL` still
overrides both if a real deployment target is supplied. **This is
sandbox-specific, not a CraftKeeper bug** — CI/production are unaffected
since they don't run behind this gateway, but any later task's e2e work
in *this* sandbox should reuse port 8123 (or check for the same
`Server: kong` signature) rather than rediscovering this.

**Playwright e2e outcome: ran and passed, not deferred.** Once the port
issue above was fixed, `tests/e2e/design-system.spec.ts` (7 tests: the
three required breakpoints × no-horizontal-scroll + axe scan, plus
StatusBadge-in-the-DOM, command-palette open/focus/Escape, mobile
drawer open/Escape, and skip-navigation-focus) ran against real
Chromium via `npm run e2e -- --grep "design system"` and all 7 passed.
The RED→GREEN evidence for this task therefore rests on both the Vitest
component tests *and* a real, observed-passing Playwright run — see
`.superpowers/sdd/task-3-report.md` for the full command output.

**A rendering discrepancy in the sandbox's own "Claude Browser" preview
tool (unrelated to Playwright) is not a product bug:** manually opening
`/design-system` in that preview tool's browser and toggling the mobile
nav drawer showed the `Sheet` stuck at its pre-animation `translateX`
(off-screen), and the tool's own screenshot action timed out repeatedly
on this page for unrelated reasons. The same drawer open/close flow was
then verified correct end-to-end in real Chromium via Playwright (test:
"below 1024px the sidebar is hidden and the nav drawer opens from the
mobile header") — passing there, on the actual test surface this task is
gated on. Treated as a quirk of that specific preview tool's rendering
engine, not a `tw-animate-css`/Sheet defect.

**Provenance vocabulary — one component, two documented value sets,
reconciled without a second CSS vocabulary:**
`Design/handoff/components.json`'s `ProvenanceBadge` entry lists 8 generic
source values (`mounted-server, catalog, hangar, modrinth, documentation,
ai-provider, administrator, api-mcp`) — all mapped verbatim to
`--ck-provenance-*` tokens. The V1 plan's own non-negotiables list a
narrower, plugin/config-specific set instead ("Built in," "Plugin,"
"Discovered," "Catalog," "Hangar," "Modrinth," or "Manual") — the set the
task brief explicitly requires `ProvenanceBadge` to represent.
`ProvenanceBadge.tsx`'s `ProvenanceSource` type uses that second,
product-facing vocabulary as its public API, with each value mapped onto
an *existing* `--ck-provenance-*` token rather than inventing new colors:
`catalog`/`hangar`/`modrinth` map 1:1; `discovered` → `mounted-server`
(found by scanning the mounted server); `manual` → `administrator`
(admin-entered); `built-in` → `documentation` (CraftKeeper's own curated
knowledge of vanilla/Paper defaults); `plugin` → `hangar` (plugin-sourced,
reusing the plugin-marketplace hue). No new CSS variables were added for
this — every value still resolves to one of the 8 tokens from
`design-tokens.json`.
