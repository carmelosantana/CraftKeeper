<?php

namespace App\Mcp\Support;

use App\Support\ApiScope;

/**
 * Consent-screen copy naming the CONCRETE consequence of each scope —
 * Task 18's ambiguity resolution #6 ("consent copy names each
 * consequence"). Deliberately separate from App\Support\ApiScope::label()
 * (a short, generic UI label reused by the /integrations/api token page)
 * because OAuth consent needs to spell out what actually happens — what a
 * human will see the connection do — not just name the ability.
 *
 * Registered with Passport::tokensCan() (App\Providers\AppServiceProvider)
 * so each description renders on the real `/oauth/authorize` consent
 * screen (resources/views/mcp/authorize.blade.php) next to the scope the
 * connecting MCP client actually requested.
 */
final class McpScopeConsequences
{
    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            ApiScope::ServerRead->value => 'Read current server reachability and player count. Read-only — never modifies anything.',
            ApiScope::ConfigRead->value => 'Read the config file inventory and REDACTED file content. Secret-flagged values (passwords, tokens, keys) are never exposed.',
            ApiScope::ConfigPropose->value => 'Propose configuration changes. Creates a change proposal only — a human must separately review and approve it in CraftKeeper before anything is written to disk.',
            ApiScope::ConfigApply->value => 'Apply an already-approved configuration change. No MCP tool in this version uses this scope.',
            ApiScope::PluginsRead->value => 'Read the installed plugin inventory: names, versions, and compatibility state only.',
            ApiScope::PluginsManage->value => 'Propose disabling or removing an installed plugin. Creates a proposal only — a human must separately review and approve it before anything changes.',
            ApiScope::ActivityRead->value => 'Read recent CraftKeeper operations: status, risk, and actor only, never a raw secret value.',
            ApiScope::RconSafe->value => 'Propose a predefined SAFE console command (e.g. "list", "say ..."). Creates a proposal only — a human must separately approve it before it reaches the server.',
            ApiScope::RconAdmin->value => 'Propose an elevated console command. No MCP tool in this version uses this scope.',
        ];
    }
}
