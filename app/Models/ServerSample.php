<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One RCON state poll (App\Console\Commands\SampleServerState, every 15
 * seconds while reachable — Task 11's ambiguity resolution #1). Bounded
 * history: pruned past 7 days by App\Console\Commands\
 * PruneServerObservationData.
 *
 * `player_count`/`player_names` are nullable and NEVER defaulted to a
 * fabricated zero/empty value when `rcon_reachable` is false or the
 * response couldn't be parsed — see App\Server\ServerStatusService, which
 * is the only reader that turns these rows into a health projection, and
 * Task 11's ambiguity resolution #5 ("Unavailable", never "0 players").
 *
 * @property int $id
 * @property Carbon $sampled_at
 * @property bool $rcon_reachable
 * @property int|null $player_count
 * @property list<string>|null $player_names
 * @property string|null $error_reason
 * @property Carbon|null $created_at
 */
#[Fillable(['sampled_at', 'rcon_reachable', 'player_count', 'player_names', 'error_reason'])]
class ServerSample extends Model
{
    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'rcon_reachable' => 'boolean',
            'player_count' => 'integer',
            'player_names' => 'array',
        ];
    }
}
