<?php

namespace App\Http\Controllers;

use App\Models\McpGrant;
use App\Support\ApiScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;

/**
 * Test-only endpoint (Task 20): mints a REAL MCP OAuth client + its
 * paired App\Models\McpGrant row for the docker-compose.integration.yml
 * stack's "MCP proposal" scenario — created EXACTLY the way
 * `App\Http\Controllers\Integrations\McpGrantController::store()`
 * creates one for a real operator (`ClientRepository::
 * createAuthorizationCodeGrantClient()`, `confidential: false` — public,
 * PKCE-only, matching Task 18's ambiguity resolution #2), so the
 * resulting client behaves identically to a production one in every
 * respect. This endpoint only skips the ADMIN clicking "Connect" in the
 * Integrations > MCP page's UI — the test runner still has to complete
 * the exact same real authorization-code + PKCE dance
 * (GET /oauth/authorize, POST the consent approval, POST /oauth/token)
 * a real MCP client does, using the already-authenticated admin session
 * cookie jar it already has from onboarding.
 *
 * (An earlier version of this endpoint tried
 * `createClientCredentialsGrantClient()` as a shortcut to skip that
 * whole dance — confirmed BY RUNNING IT for real that this cannot work
 * here: `Laravel\Passport\Guards\TokenGuard::authenticateViaBearerToken()`
 * explicitly returns null — no authenticated user at all — for any
 * client-credentials-issued token, so `auth:passport` on
 * routes/mcp.php's `/mcp/craftkeeper` route 401s before
 * App\Mcp\Support\McpGuard ever runs, regardless of scope. Every
 * production MCP token IS tied to the authorizing admin, so this
 * endpoint now mints exactly that kind.)
 *
 * MUST be impossible to reach in production — gated by the IDENTICAL,
 * doubly-enforced guard `App\Http\Controllers\E2eResetController::allowed()`
 * already uses (see that class's own docblock): registered only under
 * `app()->environment(['local', 'testing'])` AND `E2E_TESTING=true`
 * (routes/testing.php), and re-checked here regardless.
 */
class E2eMcpBootstrapController extends Controller
{
    public function __construct(
        private readonly ClientRepository $clients,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(E2eResetController::allowed(), 404);

        $data = $request->validate([
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
        ]);

        $scopes = $data['scopes'] ?? ApiScope::values();

        // Explicitly config('app.url')-rooted rather than the `url()`
        // helper: under a real (non-CLI) HTTP request, Laravel's
        // UrlGenerator derives the root from the CURRENT request's own
        // Host header via Symfony's HttpFoundation, which — empirically,
        // running this exact endpoint inside docker-compose.integration.yml
        // — drops a non-default port (nginx forwards `Host: craftkeeper:8080`
        // faithfully; something further up Symfony's own host/port
        // resolution collapses it before UrlGenerator ever sees it). A
        // non-browser test runner reading this JSON has no way to
        // recover the right port from a URL that silently lost it, so
        // this is rooted from the same APP_URL env var
        // docker-compose.integration.yml already sets, sidestepping the
        // ambiguity entirely.
        $root = rtrim((string) config('app.url'), '/');
        $redirectUri = "{$root}/__e2e__/mcp-callback";

        $client = $this->clients->createAuthorizationCodeGrantClient(
            'integration-stack-test-client',
            [$redirectUri],
            confidential: false,
        );

        $grant = McpGrant::query()->create([
            'oauth_client_id' => $client->id,
            'display_name' => 'integration-stack-test-client',
            'scopes' => array_values(array_unique($scopes)),
            'created_by' => null,
        ]);

        return response()->json([
            'client_id' => $client->id,
            'mcp_grant_id' => $grant->id,
            'redirect_uri' => $redirectUri,
            'authorize_endpoint' => "{$root}/oauth/authorize",
            'approve_endpoint' => "{$root}/oauth/authorize",
            'token_endpoint' => "{$root}/oauth/token",
            'mcp_endpoint' => "{$root}/mcp/craftkeeper",
        ]);
    }
}
