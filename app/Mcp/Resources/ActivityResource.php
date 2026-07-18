<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\McpGuard;
use App\Models\McpGrant;
use App\Models\Operation;
use App\Support\ApiScope;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Resource;

/**
 * A BOUNDED, REDACTED feed of the most recent CraftKeeper operations
 * (config/plugin/rcon/server) — status, risk, actor, timestamps, and
 * outcome. `target` is already display-redacted for a secret-shaped
 * rcon.command operation by App\Console\RconCommandService::
 * proposeCommand() before it is ever persisted, exactly like the web
 * Activity page and the REST API's OperationResource. `outcome` (free
 * operator/handler text — see App\Operations\OperationService's own
 * docblock) is, since the whole-branch fix pass, passed through
 * App\Ai\SecretRedactor at that same choke point before it is ever
 * persisted, so any known Secret/schema-flagged value it might otherwise
 * have echoed is already masked by the time it reaches this resource —
 * this resource applies no redaction of its own to it, and needs none.
 * This resource never queries App\Models\ConfigChangePayload or
 * App\Models\RconCommandPayload (the two tables that ever hold a raw
 * secret value). Requires the `activity:read` scope.
 */
#[Description('Bounded, redacted feed of the most recent CraftKeeper operations — status, risk, actor, timestamps, and outcome. Never a raw secret value.')]
class ActivityResource extends Resource
{
    private const MAX_ITEMS = 20;

    protected string $uri = 'craftkeeper://activity';

    protected string $mimeType = 'application/json';

    public function handle(Request $request, McpGuard $guard): Response
    {
        return $guard->run('resource', $this->uri(), ApiScope::ActivityRead->value, [], function (McpGrant $grant) {
            $operations = Operation::query()->orderByDesc('id')->limit(self::MAX_ITEMS)->get();

            $items = $operations->map(fn (Operation $o) => [
                'id' => $o->id,
                'type' => $o->type->value,
                'status' => $o->status->value,
                'target' => $o->target,
                'risk' => $o->risk->value,
                'actor' => [
                    'type' => $o->author_type->value,
                    'origin' => $o->author_origin,
                ],
                'created_at' => $o->created_at?->toIso8601String(),
                'outcome' => $o->outcome,
            ])->values();

            return Response::json([
                'operations' => $items->all(),
            ]);
        });
    }
}
