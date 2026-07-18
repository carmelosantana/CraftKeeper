<?php

namespace App\Models;

use App\Server\LogEventKind;
use App\Server\PlayerPlatform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One join/leave/kick/chat event, derived from a parsed
 * App\Server\LogEvent by App\Server\PlayerService::record(). Append-only
 * (no `updated_at`); bounded history, pruned past 30 days by
 * App\Console\Commands\PruneServerObservationData.
 *
 * `raw_line` is the ORIGINAL raw console line (already sanitized/bounded
 * by App\Server\LogTailService before parsing — see that class's
 * docblock) — Task 11's ambiguity resolution #4 requires every parsed
 * event to retain it.
 *
 * @property int $id
 * @property int $player_id
 * @property LogEventKind $kind
 * @property PlayerPlatform|null $platform
 * @property string|null $message
 * @property string $raw_line
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
#[Fillable(['player_id', 'kind', 'platform', 'message', 'raw_line', 'occurred_at'])]
class PlayerEvent extends Model
{
    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LogEventKind::class,
            'platform' => PlayerPlatform::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
