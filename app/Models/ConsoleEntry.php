<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sanitized, bounded (<=16 KiB — App\Server\LogTailService::
 * MAX_ENTRY_BYTES) recent console line, tailed live from the Minecraft
 * server's own log output. This is the SAME string broadcast on the
 * private `server.console` channel via App\Events\ConsoleEntryReceived —
 * see that class's docblock.
 *
 * Deliberately a BOUNDED recent buffer, not a long-term log store (Task
 * 11's ambiguity resolution #2/#3): App\Server\LogTailService trims this
 * table to its most recent MAX_CONSOLE_ENTRIES rows synchronously on
 * every write, and App\Console\Commands\PruneServerObservationData also
 * age-prunes it daily as a second bound. Arbitrary historical log search
 * stays on disk (the Minecraft server's own log files), not here.
 *
 * @property int $id
 * @property string $line
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 */
#[Fillable(['line', 'occurred_at'])]
class ConsoleEntry extends Model
{
    const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
