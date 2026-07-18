<?php

use App\Http\Controllers\E2eResetController;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Test-only route: NEVER reachable in production.
 *
 * `POST /__e2e__/reset` lets the Playwright suite reset the database to a
 * clean, freshly-migrated state so each spec file can establish exactly
 * the baseline it needs (see tests/e2e/*.spec.ts `beforeAll`), rather than
 * depending on whatever an earlier spec file left behind or the order the
 * suite happened to run in — see docs/architecture/decisions.md for the
 * full story of the bug this fixes.
 *
 * This file is required unconditionally from routes/web.php (matching the
 * routes/settings.php pattern), but the route itself is registered ONLY
 * when BOTH of these hold:
 *
 *   - app()->environment(['local', 'testing']) — the Dockerfile hard-codes
 *     `APP_ENV=production` for every real deployment, and
 *     config('app.env') defaults to 'production' if APP_ENV is ever unset.
 *   - config('craftkeeper.e2e_testing') is exactly `true` — sourced from
 *     the E2E_TESTING env var, which is set ONLY in playwright.config.ts's
 *     `webServer.env`. It is not present in .env, .env.example,
 *     compose.example.yml, or the Dockerfile.
 *
 * E2eResetController re-checks this identical guard itself and 404s if it
 * fails, so even an accidental future registration of this route outside
 * this `if` still cannot execute it.
 *
 * `withoutMiddleware([...])` mirrors the `/up` health route in
 * routes/web.php: Playwright's `request` fixture (used from spec files'
 * `beforeAll`, before any browser page/cookie jar exists) has no CSRF
 * token or session cookie to present, and this endpoint needs neither —
 * it authenticates nobody and reads no session state, it only resets the
 * database.
 */
if (E2eResetController::allowed()) {
    Route::post('__e2e__/reset', E2eResetController::class)
        ->withoutMiddleware([
            StartSession::class,
            EncryptCookies::class,
            PreventRequestForgery::class,
            ShareErrorsFromSession::class,
        ])
        ->name('e2e.reset');
}
