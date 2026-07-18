<?php

use App\Mcp\Servers\CraftKeeperServer;
use App\Mcp\Support\McpOAuthMetadata;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/**
 * Task 18: the guarded MCP endpoint. Loaded explicitly by
 * App\Providers\AppServiceProvider::registerMcpRoutes() — NOT the
 * laravel/mcp package's own routes/ai.php auto-discovery (this file is
 * named routes/mcp.php on purpose, so it stays outside that convention and
 * under our own explicit control) — OUTSIDE the 'web'/'api' middleware
 * groups bootstrap/app.php's withRouting() configures. That keeps
 * /mcp/craftkeeper a stateless, CSRF-free, bearer-token-only JSON-RPC
 * endpoint (no session dependency, unlike every Inertia page in this
 * app).
 *
 * `auth:passport` is the ONLY middleware on the MCP route itself: it
 * rejects EVERY request without a valid Passport access token with a 401
 * (+ WWW-Authenticate header, via laravel/mcp's own
 * AddWwwAuthenticateHeader middleware, already applied inside
 * Laravel\Mcp\Server\Registrar::web()) before ANY JSON-RPC method —
 * including tools/list, resources/list, and prompts/list, which never
 * touch a Tool/Resource's own App\Mcp\Support\McpGuard check — is ever
 * dispatched. This is "no anonymous MCP access in V1" (Task 18's
 * ambiguity resolution #2), enforced at the front door in addition to the
 * per-call grant/scope checks every Tool/Resource performs itself.
 *
 * Deliberately does NOT call Laravel\Mcp\Facades\Mcp::oauthRoutes() — that
 * helper ALSO registers a dynamic client registration endpoint
 * (POST /oauth/register, Laravel\Mcp\Server\Http\Controllers\
 * OAuthRegisterController), which V1 explicitly forbids ("no dynamic
 * registration"). The two .well-known discovery documents below are
 * hand-written instead, mirroring Registrar::oauthRoutes()'s own route
 * names/shape but WITHOUT a `registration_endpoint` key, so no such
 * capability is ever advertised — and, because the POST /oauth/register
 * route is simply never registered anywhere in this application, none
 * exists to reach regardless of what a client requests (see
 * tests/Feature/Mcp/McpOAuthTest.php's route-enumeration assertion).
 *
 * `authorization_endpoint`/`token_endpoint` point at Laravel Passport's
 * OWN stock routes (registered by laravel/passport itself — `oauth/
 * authorize`, `oauth/token`), configured for authorization-code + PKCE
 * only by App\Providers\AppServiceProvider::configurePassport().
 */
Mcp::web('/mcp/craftkeeper', CraftKeeperServer::class)
    // Task 20: 'throttle:mcp' runs AFTER 'auth:passport' so a bearer
    // token is validated first — the rate limit
    // (App\Providers\AppServiceProvider::configureApiRateLimiting())
    // keys by the authenticated OAuth CLIENT id, falling back to IP only
    // for the (already-401-rejected-first) unauthenticated case.
    ->middleware(['auth:passport', 'throttle:mcp'])
    ->name('mcp.craftkeeper');

Route::get('/.well-known/oauth-protected-resource', fn () => response()->json(
    McpOAuthMetadata::protectedResource(url('/mcp/craftkeeper'))
))->name('mcp.oauth.protected-resource');

Route::get('/.well-known/oauth-protected-resource/{path}', fn (string $path) => response()->json(
    McpOAuthMetadata::protectedResource(url('/'.$path))
))->where('path', '.*')->name('mcp.oauth.protected-resource.nested');

Route::get('/.well-known/oauth-authorization-server', fn () => response()->json(
    McpOAuthMetadata::authorizationServer()
))->name('mcp.oauth.authorization-server');

Route::get('/.well-known/oauth-authorization-server/{path}', fn (string $path) => response()->json(
    McpOAuthMetadata::authorizationServer()
))->where('path', '.*')->name('mcp.oauth.authorization-server.nested');
