<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Test-only endpoint: resets CraftKeeper to a clean, freshly-migrated
 * database so each Playwright e2e spec file can establish its own
 * deterministic baseline in `beforeAll` (fresh/no-admin for onboarding;
 * fresh-then-onboarded for configuration/design-system), independent of
 * whatever any other spec file left behind or what order the suite ran
 * in.
 *
 * MUST be impossible to reach in production. This is enforced twice:
 *
 *   1. routes/testing.php only registers this route when
 *      app()->environment(['local', 'testing']) AND the dedicated
 *      `craftkeeper.e2e_testing` config flag (env: E2E_TESTING) are both
 *      true. That flag is set ONLY in playwright.config.ts's
 *      `webServer.env` — never in .env, .env.example, compose.example.yml,
 *      or the Dockerfile (which hard-codes `APP_ENV=production`).
 *   2. This handler re-checks the IDENTICAL guard itself and `abort(404)`s
 *      if it fails, so even an accidental future registration of this
 *      route outside that guard still cannot execute it.
 *
 * It takes no input, does nothing but reset the test database, and is
 * never referenced by any production code path. See
 * docs/architecture/decisions.md for the full rationale.
 */
class E2eResetController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(self::allowed(), 404);

        Artisan::call('migrate:fresh', ['--force' => true]);

        return response()->noContent();
    }

    public static function allowed(): bool
    {
        return app()->environment(['local', 'testing'])
            && config('craftkeeper.e2e_testing') === true;
    }
}
