<?php

namespace App\Http\Controllers;

use App\Testing\E2ePluginFixtures;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Test-only endpoint: serves the real, deterministic e2e fixture plugin
 * JAR bytes (App\Testing\E2ePluginFixtures) that App\Testing\
 * E2eFixturePluginSource's release() download URLs point at — the
 * same-origin stand-in for a real plugin host, used only so Playwright
 * (which drives a real running server with no Http::fake() available)
 * can exercise a REAL download+checksum-verify+install through the
 * actual UI.
 *
 * MUST be impossible to reach in production — enforced identically to
 * App\Http\Controllers\E2eResetController (see that class's docblock):
 * routes/testing.php only registers the route when
 * E2eResetController::allowed() is true, and this handler re-checks the
 * identical guard itself.
 */
class E2ePluginFixtureController extends Controller
{
    public function __invoke(string $version): Response
    {
        abort_unless(E2eResetController::allowed(), 404);

        try {
            $bytes = E2ePluginFixtures::jarBytesFor($version);
        } catch (RuntimeException) {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/java-archive',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }
}
