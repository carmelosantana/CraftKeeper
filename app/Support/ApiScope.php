<?php

namespace App\Support;

/**
 * The exact, fixed set of scoped abilities a /api/v1 personal access token
 * may carry — verbatim from the Task 17 brief's Step 2. Sanctum stores
 * these as plain string "abilities" on the token
 * (App\Models\User::createToken($name, $abilities)); every /api/v1 route
 * is gated by exactly one (or, for the rcon command endpoint, an
 * explicit "any of two" pair) of these values via
 * App\Http\Middleware\EnsureApiScope.
 *
 * There is deliberately no "approve" scope anywhere in this enum, and no
 * combination of these scopes ever reaches
 * App\Operations\OperationService::approve() — that method only accepts a
 * real, authenticated App\Models\User approving through the session-based
 * web UI (App\Http\Controllers\ConfigController::approve(),
 * ConsoleController::approve(), PluginController::approve()), never an
 * API token. See docs/architecture/decisions.md's Task 17 entry for the
 * full "no API path may approve" reconciliation.
 *
 * Read access never implies write/propose/apply/rcon: ConfigRead does not
 * grant ConfigPropose, PluginsRead does not grant PluginsManage, and
 * RconSafe does not grant RconAdmin (the reverse — an RconAdmin token
 * proposing a command CommandPolicy classifies as Safe — is intentionally
 * ALLOWED, since "admin" is a strict superset of "safe" for this one
 * resource; see App\Http\Controllers\Api\V1\OperationController::rcon()).
 */
enum ApiScope: string
{
    case ServerRead = 'server:read';
    case ConfigRead = 'config:read';
    case ConfigPropose = 'config:propose';
    case ConfigApply = 'config:apply';
    case PluginsRead = 'plugins:read';
    case PluginsManage = 'plugins:manage';
    case ActivityRead = 'activity:read';
    case RconSafe = 'rcon:safe';
    case RconAdmin = 'rcon:admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * A short, human-readable label for the token-management UI
     * (resources/js/pages/integrations/Api.tsx) — never used for anything
     * security-relevant, purely display.
     */
    public function label(): string
    {
        return match ($this) {
            self::ServerRead => 'Read server status',
            self::ConfigRead => 'Read configuration files',
            self::ConfigPropose => 'Propose configuration changes',
            self::ConfigApply => 'Apply approved configuration changes',
            self::PluginsRead => 'Read installed plugins',
            self::PluginsManage => 'Propose plugin changes',
            self::ActivityRead => 'Read operation activity',
            self::RconSafe => 'Propose safe console commands',
            self::RconAdmin => 'Propose elevated console commands',
        };
    }
}
