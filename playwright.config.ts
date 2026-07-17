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

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'list',
    use: {
        baseURL: process.env.APP_URL ?? `http://${HOST}:${PORT}`,
        trace: 'on-first-retry',
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
    webServer: {
        command: `npm run build && php artisan serve --host=${HOST} --port=${PORT}`,
        url: `http://${HOST}:${PORT}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
