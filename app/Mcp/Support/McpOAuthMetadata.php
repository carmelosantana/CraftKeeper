<?php

namespace App\Mcp\Support;

use App\Support\ApiScope;

/**
 * RFC 8414 authorization-server metadata for CraftKeeper's MCP OAuth
 * integration (routes/mcp.php's `.well-known/oauth-authorization-server`
 * documents). Deliberately a plain static-method class, not a function
 * declared inside routes/mcp.php itself — that route file is `require`d
 * (not `require_once`) by Illuminate\Routing\RouteFileRegistrar every time
 * the application boots (once per real request, and once per test in this
 * suite's per-test fresh Application), so a top-level named function
 * declared there would fatal with "Cannot redeclare" the second time it
 * loads in the same PHP process; an autoloaded class has no such problem.
 *
 * Deliberately carries NO `registration_endpoint` key — V1 supports no
 * dynamic client registration (see routes/mcp.php's own docblock);
 * `grant_types_supported` is exactly `['authorization_code',
 * 'refresh_token']`, matching the ONLY grant types any OAuth client this
 * application ever creates is stored with (Laravel\Passport\
 * ClientRepository::createAuthorizationCodeGrantClient(), called from
 * App\Http\Controllers\Integrations\McpGrantController::store()).
 */
final class McpOAuthMetadata
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public static function authorizationServer(): array
    {
        return [
            'issuer' => url('/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ApiScope::values(),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ];
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public static function protectedResource(string $resourceUrl): array
    {
        return [
            'resource' => $resourceUrl,
            'authorization_servers' => [url('/')],
            'scopes_supported' => ApiScope::values(),
        ];
    }
}
