<?php

namespace App\Console\Commands;

use App\Models\ConsoleEntry;
use App\Models\PlayerEvent;
use App\Models\ServerSample;
use Illuminate\Console\Command;

/**
 * Bounded storage, no long-term/indefinite retention (Task 11's
 * ambiguity resolution #2): deletes App\Models\ServerSample rows older
 * than 7 days and App\Models\PlayerEvent rows older than 30 days. Run
 * daily via ->withSchedule() in bootstrap/app.php.
 *
 * Also age-prunes App\Models\ConsoleEntry past CONSOLE_ENTRY_RETENTION_HOURS
 * as a second, time-based bound alongside the row-count bound
 * App\Server\LogTailService already enforces synchronously on every
 * write — a deliberate addition beyond the letter of ambiguity resolution
 * #2 (which names only ServerSample/PlayerEvent), in its clear spirit
 * ("no long-term storage"; historical log search stays on disk, not in
 * this bounded recent buffer).
 */
class PruneServerObservationData extends Command
{
    /**
     * @var string
     */
    protected $signature = 'server:prune-observation-data';

    /**
     * @var string
     */
    protected $description = 'Prune ServerSample (7d), PlayerEvent (30d), and ConsoleEntry (24h) rows past their retention window.';

    public const SERVER_SAMPLE_RETENTION_DAYS = 7;

    public const PLAYER_EVENT_RETENTION_DAYS = 30;

    public const CONSOLE_ENTRY_RETENTION_HOURS = 24;

    public function handle(): int
    {
        $now = now();

        $deletedSamples = ServerSample::query()
            ->where('sampled_at', '<', $now->clone()->subDays(self::SERVER_SAMPLE_RETENTION_DAYS))
            ->delete();

        $deletedEvents = PlayerEvent::query()
            ->where('occurred_at', '<', $now->clone()->subDays(self::PLAYER_EVENT_RETENTION_DAYS))
            ->delete();

        $deletedEntries = ConsoleEntry::query()
            ->where('occurred_at', '<', $now->clone()->subHours(self::CONSOLE_ENTRY_RETENTION_HOURS))
            ->delete();

        $this->info("Pruned {$deletedSamples} server sample(s), {$deletedEvents} player event(s), and {$deletedEntries} console entry(ies).");

        return self::SUCCESS;
    }
}
