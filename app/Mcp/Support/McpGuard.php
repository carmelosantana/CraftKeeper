<?php

namespace App\Mcp\Support;

use App\Models\McpAuditEvent;
use App\Models\McpGrant;
use App\Operations\InputRedactor;
use App\Policies\McpGrantPolicy;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Support\ValidationMessages;
use Laravel\Passport\Client;
use Laravel\Passport\Guards\TokenGuard;
use Throwable;

/**
 * The single choke point every MCP tool/resource/prompt call passes
 * through: resolve the calling App\Models\McpGrant (via the request's
 * Passport-authenticated OAuth CLIENT identity), enforce
 * App\Policies\McpGrantPolicy, and record a FULL audit trail entry
 * (App\Models\McpAuditEvent) — client, subject, scope decision,
 * correlation id, REDACTED arguments, duration, and outcome — for EVERY
 * single call, whether it is allowed, denied, or errors. Nothing reaches a
 * Tool/Resource's real logic without passing through here first, and
 * every Response this returns is a normal, catchable MCP response — never
 * an uncaught exception (see run()'s try/catch), so denial behaves
 * identically for tools (caught by Laravel\Mcp\Server\Methods\CallTool)
 * and resources (Laravel\Mcp\Server\Methods\ReadResource, which does NOT
 * catch AuthorizationException the way CallTool does).
 *
 * Resolves via Laravel\Passport\Guards\TokenGuard::client() — the OAuth
 * CLIENT identity — rather than the authenticated USER's
 * `currentAccessToken()`. Two reasons: (1) it is statically, precisely
 * typed (`?Laravel\Passport\Client`), unlike `Illuminate\Contracts\Auth\
 * Authenticatable::currentAccessToken()`, which does not exist on that
 * interface at all and is only resolvable through App\Models\User's
 * Sanctum-templated `HasApiTokens` trait — mixing the two token systems
 * on one model made that call's STATIC type always resolve to
 * `Laravel\Sanctum\PersonalAccessToken`, even though the REAL runtime
 * value on the 'passport' guard is a `Laravel\Passport\AccessToken`; (2)
 * it is the right authorization boundary anyway — CraftKeeper's grant
 * ceiling is per-CLIENT (App\Models\McpGrant::$oauth_client_id), not
 * per-token, and `client()` resolves identically (and correctly) whether
 * Passport authenticated the request via a bearer token or (unused in
 * this app, but handled the same, non-bypassable way) its first-party
 * cookie flow — unlike Laravel\Sanctum\TransientToken's unconditional
 * `can()`, Passport's cookie path still resolves a REAL client id from
 * the token's own `aud` claim, so there is no analogous "any session
 * bypasses every scope check" hole to defend against here (see Task 17's
 * EnsureApiScope for the Sanctum finding this pattern deliberately
 * avoids reintroducing).
 *
 * Test seam: tests/Concerns/CallsMcp.php uses Passport's OWN official
 * testing helper, Laravel\Passport\Passport::actingAsClient($client, [],
 * 'passport'), which calls the exact same `TokenGuard::setClient()`
 * production code populates internally after validating a real bearer
 * token — so grant resolution runs through the IDENTICAL code path in
 * both cases.
 */
final class McpGuard
{
    public function __construct(
        private readonly McpGrantPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(McpGrant): Response  $callback
     */
    public function run(string $subjectType, string $subjectName, ?string $scope, array $arguments, callable $callback): Response
    {
        $start = microtime(true);
        $correlationId = (string) Str::uuid();
        $grant = $this->resolveGrant();
        $decision = $this->policy->authorize($grant, $scope);

        if ($decision !== true) {
            $this->audit($grant, $subjectType, $subjectName, $scope, $correlationId, $arguments, 'denied', $decision, $start);

            return Response::error($decision);
        }

        $grant->forceFill(['last_used_at' => now()])->save();

        try {
            $response = $callback($grant);
        } catch (ValidationException $e) {
            // Caught here (rather than left to propagate to Laravel\Mcp\
            // Server\Methods\CallTool's own ValidationException handling)
            // so an invalid-input call is audited exactly like every
            // other outcome — full audit coverage takes priority over
            // reusing the vendor's own catch site. The message itself
            // still comes from the SAME Laravel\Mcp\Support\
            // ValidationMessages formatter CallTool would have used, so
            // the caller-facing text is unchanged either way.
            $message = ValidationMessages::from($e);
            $this->audit($grant, $subjectType, $subjectName, $scope, $correlationId, $arguments, 'error', $message, $start);

            return Response::error($message);
        } catch (Throwable $e) {
            report($e);
            $this->audit($grant, $subjectType, $subjectName, $scope, $correlationId, $arguments, 'error', 'An unexpected error occurred.', $start);

            return Response::error('An unexpected error occurred while handling this request.');
        }

        $this->audit(
            $grant,
            $subjectType,
            $subjectName,
            $scope,
            $correlationId,
            $arguments,
            $response->isError() ? 'denied' : 'allowed',
            null,
            $start,
        );

        return $response;
    }

    private function resolveGrant(): ?McpGrant
    {
        return $this->grantForGuard(Auth::guard('passport'));
    }

    /**
     * Takes the plain `Illuminate\Contracts\Auth\Guard` interface — the
     * TRUE, official return type of `Auth::guard()` — as its parameter,
     * deliberately, rather than inlining this check where `Auth::guard()`
     * is called: Larastan's Auth reflection extension speculatively
     * narrows `Auth::guard('passport')` to `Illuminate\Auth\RequestGuard`
     * inline (it cannot see that `Laravel\Passport\PassportServiceProvider`
     * registers the 'passport' DRIVER via `Auth::extend()` to actually
     * construct a `Laravel\Passport\Guards\TokenGuard` — a closure it has
     * no way to statically evaluate), which would make a direct
     * `instanceof TokenGuard` check there report as "always false" even
     * though it is true at runtime. Crossing a function boundary with an
     * explicitly, correctly typed parameter resets analysis to that
     * DECLARED (interface) type, so the instanceof check below is
     * evaluated honestly instead of against Larastan's incorrect guess.
     */
    private function grantForGuard(Guard $guard): ?McpGrant
    {
        if (! $guard instanceof TokenGuard) {
            return null;
        }

        $client = $guard->client();

        if (! $client instanceof Client) {
            return null;
        }

        return McpGrant::query()->where('oauth_client_id', $client->getKey())->first();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function audit(
        ?McpGrant $grant,
        string $subjectType,
        string $subjectName,
        ?string $scope,
        string $correlationId,
        array $arguments,
        string $outcome,
        ?string $denialReason,
        float $start,
    ): void {
        McpAuditEvent::query()->create([
            'mcp_grant_id' => $grant?->id,
            'subject_type' => $subjectType,
            'subject_name' => $subjectName,
            'scope' => $scope,
            'correlation_id' => $correlationId,
            'arguments' => InputRedactor::redact($arguments),
            'outcome' => $outcome,
            'denial_reason' => $denialReason,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
    }
}
