<?php

namespace App\Http\Resources\Api\V1;

use App\Server\ServerStatusSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps App\Server\ServerStatusSnapshot — a plain value object, not an
 * Eloquent model — for GET /api/v1/server/status. Nothing on this
 * snapshot is ever secret-shaped (player names/counts, RCON/log
 * reachability), so no redaction pass is needed here.
 *
 * @mixin ServerStatusSnapshot
 */
class ServerStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ServerStatusSnapshot $snapshot */
        $snapshot = $this->resource;

        return [
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
        ];
    }
}
