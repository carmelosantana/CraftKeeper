<?php

namespace App\Policies;

use App\Models\McpGrant;

/**
 * The scoped-grant hard boundary for every MCP tool/resource/prompt call —
 * Task 18's crux, mirroring App\Http\Middleware\EnsureApiScope's role for
 * /api/v1 (Task 17). Authorizes against App\Models\McpGrant ONLY — never
 * against a live Passport access token's own `oauth_scopes` (see that
 * model's class docblock for why the grant, not the token, is
 * authoritative).
 *
 * A revoked or expired grant fails EVERY call regardless of scope; a
 * missing grant (no bearer token, or a token belonging to a client with no
 * McpGrant row at all) fails every call too. Read access never implies
 * write/propose — `config:read` does not grant `config:propose`, exactly
 * like Task 17's ApiScope enforcement.
 */
class McpGrantPolicy
{
    /**
     * Authorize a request against a specific required scope. Pass a null
     * `$scope` for a primitive that needs nothing beyond "a valid,
     * non-revoked, non-expired grant exists" (e.g. the read-only
     * DiagnoseServer prompt, which touches no protected data itself).
     *
     * @return true|string true when authorized; otherwise a human-readable denial reason
     */
    public function authorize(?McpGrant $grant, ?string $scope): true|string
    {
        if ($grant === null) {
            return 'No active MCP grant is associated with this request.';
        }

        if ($grant->isRevoked()) {
            return 'This MCP grant has been revoked.';
        }

        if ($grant->isExpired()) {
            return 'This MCP grant has expired.';
        }

        if ($scope !== null && ! $grant->hasScope($scope)) {
            return "This MCP grant does not carry the required scope [{$scope}].";
        }

        return true;
    }
}
