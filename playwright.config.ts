import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

// `127.0.0.1:8123` — not `localhost`, and deliberately not one of the
// "well-known" dev ports (3000/5173/8000/8080/...). In this sandbox those
// are bound to an outer Kong gateway that answers every request itself
// with `401 { "message": "Unauthorized" }` (`WWW-Authenticate: Basic
// realm="kong"`) before it ever reaches `php artisan serve` — confirmed
// with a plain `curl 127.0.0.1:8000/up` returning that same 401 with no
// server running on the port at all. Port 8123 is not intercepted. See
// docs/architecture/decisions.md (Task 3) for the full investigation.
const PORT = process.env.APP_URL
    ? new URL(process.env.APP_URL).port || '80'
    : '8123';
const HOST = process.env.APP_URL
    ? new URL(process.env.APP_URL).hostname
    : '127.0.0.1';

// Task 9: the configuration e2e spec needs a REAL, WRITABLE Minecraft root
// with recognizable config files — `config/craftkeeper.php`'s local/test
// fallback (`storage_path('craftkeeper/minecraft')`) does not exist by
// default and must never be the git-tracked `tests/fixtures/minecraft`
// (Feature tests read that fixture directly and it must stay pristine —
// see tests/Unit/Filesystem/MinecraftPathTest.php). `webServer.command`
// below refreshes a disposable copy of it here on every fresh server boot,
// the same way it already refreshes the sqlite database via
// `migrate:fresh`.
const E2E_MINECRAFT_ROOT = path.resolve(
    process.cwd(),
    'storage/craftkeeper/e2e-minecraft',
);

export default defineConfig({
    testDir: './tests/e2e',
    // Every spec file shares ONE real server process backed by ONE
    // sqlite file (see `webServer` below) — there is no per-worker
    // isolation. Each spec file resets that shared database to its own
    // deterministic baseline in `beforeAll` via the test-only
    // `/__e2e__/reset` endpoint (see routes/testing.php), which makes
    // specs independent of each other's state — but that independence
    // only holds if resets and requests from different spec files never
    // interleave. `fullyParallel: false` + `workers: 1` (always, not just
    // CI) guarantees exactly one spec file's tests run at a time, so a
    // reset can never race a request from another file. See "Task: e2e
    // isolation" in docs/architecture/decisions.md.
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: 'list',
    use: {
        baseURL: process.env.APP_URL ?? `http://${HOST}:${PORT}`,
        trace: 'on-first-retry',
        // The app already uses `data-test="..."` (not the Playwright
        // default `data-testid`) as its test-hook convention throughout
        // resources/js/pages — see e.g. login.tsx's `data-test="login-button"`.
        testIdAttribute: 'data-test',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    // Serve a production build behind `php artisan serve` — simpler and
    // more deterministic in CI than running the Vite dev server, and it
    // exercises the exact assets `npm run build` produces. Reuses an
    // already-running server locally (e.g. one left over from manual
    // testing); CI always boots a fresh one.
    //
    // Task 4: `migrate:fresh` resets the (real, file-backed) local sqlite
    // database before every fresh server boot. The onboarding/login/2FA
    // specs are stateful against real InstallationState — they create the
    // one-and-only admin account and depend on starting from zero users,
    // so each fresh run needs a clean slate. `reuseExistingServer` above
    // means this only runs when no server is already listening on the
    // port; a manually-left-running `artisan serve` is reused as-is.
    webServer: {
        command:
            `rm -rf ${E2E_MINECRAFT_ROOT} && mkdir -p ${E2E_MINECRAFT_ROOT} && cp -r tests/fixtures/minecraft/. ${E2E_MINECRAFT_ROOT}/ && ` +
            `php artisan migrate:fresh --force && npm run build && php artisan serve --no-reload --host=${HOST} --port=${PORT}`,
        url: `http://${HOST}:${PORT}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
        stdout: 'pipe',
        stderr: 'pipe',
        env: {
            MINECRAFT_ROOT: E2E_MINECRAFT_ROOT,
            // Enables the test-only POST /__e2e__/reset endpoint (see
            // routes/testing.php + app/Http/Controllers/
            // E2eResetController.php) that each spec file's `beforeAll`
            // calls to give itself a clean, freshly-migrated database
            // regardless of what any other spec file already did to it —
            // that's what makes the full suite order-independent. Set
            // ONLY here: this flag must never appear in .env,
            // .env.example, compose.example.yml, or the Dockerfile. Even
            // if it were, the route also requires
            // app()->environment(['local', 'testing']) — the Dockerfile
            // hard-codes APP_ENV=production — and the controller
            // re-checks both conditions itself before doing anything.
            E2E_TESTING: 'true',
            // Task 15: PHP's built-in dev server (what `artisan serve`
            // wraps) handles exactly one request at a time by default —
            // fine for every prior spec, but tests/e2e/plugins.spec.ts
            // exercises a SELF-REFERENTIAL download (App\Plugins\
            // PluginDownloader fetching a same-origin e2e fixture jar
            // from App\Http\Controllers\E2ePluginFixtureController,
            // mid-request, while handling the outer install/update
            // request) — with only one worker that deadlocks the server
            // against itself. `--no-reload` is required for Laravel's
            // ServeCommand to honor PHP_CLI_SERVER_WORKERS at all (it
            // warns and silently falls back to one otherwise).
            PHP_CLI_SERVER_WORKERS: '4',
        },
    },
});
