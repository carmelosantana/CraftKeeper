<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\McpGuard;
use App\Models\McpGrant;
use App\Server\ServerStatusService;
use App\Support\ApiScope;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Resource;

/**
 * Bounded, read-only current server status — the same
 * App\Server\ServerStatusService::snapshot() aggregation
 * App\Http\Controllers\ServerController (web) and
 * App\Http\Controllers\Api\V1\ServerController (REST) already use. Nothing
 * on this snapshot is ever secret-shaped (RCON/log reachability, player
 * count/names), and it never fabricates a positive player count when the
 * real state is unknown — see that service's own docblock. Requires the
 * `server:read` scope.
 */
#[Description('Bounded, redacted current server status: RCON reachability and player count/names when available, and server log accessibility. Never fabricates a player count when the real state is unknown.')]
class ServerStatusResource extends Resource
{
    protected string $uri = 'craftkeeper://server/status';

    protected string $mimeType = 'application/json';

    public function handle(Request $request, McpGuard $guard, ServerStatusService $status): Response
    {
        return $guard->run('resource', $this->uri(), ApiScope::ServerRead->value, [], function (McpGrant $grant) use ($status) {
            $snapshot = $status->snapshot();

            return Response::json([
                'rcon' => [
                    'available' => $snapshot->rcon->available,
                    'reason' => $snapshot->rcon->reason,
                    'player_count' => $snapshot->rcon->playerCount,
                    'player_names' => $snapshot->rcon->playerNames,
                    'sampled_at' => $snapshot->rcon->sampledAt?->toIso8601String(),
                ],
                'logs' => [
                    'available' => $snapshot->logs->available,
                    'reason' => $snapshot->logs->reason,
                ],
            ]);
        });
    }
}
