<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The scope hard boundary for every /api/v1 route — Task 17's crux.
 * Resolves the request through Sanctum's 'sanctum' guard and requires the
 * resolved principal to be authenticated by a GENUINE, scoped
 * Laravel\Sanctum\PersonalAccessToken carrying AT LEAST ONE of the given
 * App\Support\ApiScope values. Anything else — no token at all, or a
 * token missing every listed scope — is rejected with the shared
 * {code, message, details, correlation_id} JSON error shape
 * (App\Support\Api\ApiError), never a silent pass-through.
 *
 * `Route::middleware('scope:config:propose')` / `'scope:rcon:safe,rcon:admin'`
 * both parse correctly under Laravel's own middleware-parameter syntax:
 * only the FIRST ':' in the route-middleware string separates the alias
 * ("scope") from its parameter list, so a scope value that itself
 * contains a ':' (every App\Support\ApiScope case does, e.g.
 * "config:propose") survives intact as one parameter; multiple scopes are
 * still comma-separated ("rcon:safe,rcon:admin" -> two params).
 *
 * CRITICAL, verified-against-source subtlety this middleware exists to
 * close: Laravel\Sanctum\Guard::__invoke() checks the 'web' SESSION guard
 * FIRST, unconditionally — there is no "is this a stateful domain" gate
 * in the guard itself (that only affects whether the
 * EnsureFrontendRequestsAreStateful middleware runs at all, which this
 * app never applies to /api/v1). When a request carries a valid 'web'
 * session, Sanctum authenticates it and wraps the user in a
 * Laravel\Sanctum\TransientToken — and TransientToken::can($ability)
 * ALWAYS returns true, unconditionally, for every ability. If this
 * middleware only checked `$user->tokenCan($scope)` (as an earlier
 * version of this class did), an admin's own already-logged-in browser
 * tab would satisfy EVERY scope check with no token at all — exactly the
 * bypass Task 17's "a token's scope is a hard boundary" requirement
 * forbids. Explicitly requiring `$user->currentAccessToken()` to be an
 * `instanceof PersonalAccessToken` (never true for a TransientToken)
 * closes this: a session-authenticated request is rejected with 401 here,
 * getting exactly as far into /api/v1 as an anonymous request. Only a
 * genuine bearer-token request — which Guard::__invoke() resolves via
 * `PersonalAccessToken::findToken()` and attaches with the real model,
 * never a TransientToken — can ever pass this check.
 */
class EnsureApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $user = $request->user('sanctum');
        $token = $user?->currentAccessToken();

        if ($user === null || ! $token instanceof PersonalAccessToken) {
            return ApiError::response($request, 401, 'unauthenticated', 'A valid API token is required.');
        }

        foreach ($scopes as $scope) {
            if ($token->can($scope)) {
                return $next($request);
            }
        }

        return ApiError::response(
            $request,
            403,
            'forbidden_scope',
            'This token does not carry a required scope for this endpoint.',
            ['required_scope' => count($scopes) === 1 ? $scopes[0] : array_values($scopes)],
        );
    }
}
