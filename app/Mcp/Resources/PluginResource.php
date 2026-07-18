<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\McpGuard;
use App\Models\McpGrant;
use App\Models\PluginInstallation;
use App\Plugins\PluginInventoryService;
use App\Support\ApiScope;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Resource;

/**
 * A BOUNDED inventory of installed plugins — name, version, enabled state,
 * provenance, dependency lists, and compatibility state only, never the
 * plugin bytes themselves. Reuses
 * App\Plugins\PluginInventoryService::reconcile() and
 * App\Models\PluginInstallation exactly like
 * App\Http\Controllers\Api\V1\PluginController::index(). Requires the
 * `plugins:read` scope.
 */
#[Description('Bounded inventory of installed plugins: name, version, enabled state, provenance, dependencies, and compatibility state. Never the plugin bytes themselves.')]
class PluginResource extends Resource
{
    private const MAX_ITEMS = 100;

    protected string $uri = 'craftkeeper://plugins';

    protected string $mimeType = 'application/json';

    public function handle(Request $request, McpGuard $guard, PluginInventoryService $inventory): Response
    {
        return $guard->run('resource', $this->uri(), ApiScope::PluginsRead->value, [], function (McpGrant $grant) use ($inventory) {
            $inventory->reconcile();

            $installations = PluginInstallation::query()
                ->orderBy('relative_path')
                ->limit(self::MAX_ITEMS + 1)
                ->get();

            $truncated = $installations->count() > self::MAX_ITEMS;

            $items = $installations->take(self::MAX_ITEMS)->map(fn (PluginInstallation $p) => [
                'relative_path' => $p->relative_path,
                'name' => $p->name,
                'version' => $p->version,
                'enabled' => $p->enabled,
                'provenance' => $p->provenance,
                'compatibility_state' => $p->compatibility_state?->value,
                'hard_dependencies' => $p->hard_dependencies,
                'soft_dependencies' => $p->soft_dependencies,
                'last_seen_at' => $p->last_seen_at?->toIso8601String(),
            ])->values();

            return Response::json([
                'plugins' => $items->all(),
                'truncated' => $truncated,
            ]);
        });
    }
}
