<?php

namespace App\Models;

use App\Server\PlayerPlatform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A player identity, keyed by the exact username string CraftKeeper has
 * observed in console output — never a looked-up or fabricated
 * Mojang/Xbox UUID (Task 11's ambiguity resolution #4). Populated
 * exclusively by App\Server\PlayerService from parsed
 * App\Server\LogEvent join/leave/kick/chat events; `platform` reflects the
 * most recently observed value (a Floodgate-tagged join can retroactively
 * confirm Bedrock for a username that was first seen via a plain vanilla
 * line and therefore defaulted to Java).
 *
 * @property int $id
 * @property string $username
 * @property PlayerPlatform $platform
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
#[Fillable(['username', 'platform', 'first_seen_at', 'last_seen_at'])]
class Player extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => PlayerPlatform::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PlayerEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PlayerEvent::class);
    }
}
