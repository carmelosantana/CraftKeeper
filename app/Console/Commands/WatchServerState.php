<?php

namespace App\Console\Commands;

use App\Console\PersistentRconClient;
use App\Server\ServerSampler;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * The RCON health poll, as ONE long-running process holding ONE RCON
 * connection — run by Supervisor alongside queue:work and reverb:start
 * (docker/supervisor/supervisord.conf).
 *
 * WHY THIS IS NOT A SCHEDULED COMMAND. It used to be: `$schedule->
 * command(SampleServerState::class)->everyFifteenSeconds()`. Laravel runs
 * a scheduled COMMAND event by shelling out — Illuminate\Console\
 * Scheduling\CommandBuilder builds an `artisan` invocation and Event::run()
 * hands it to Process::fromShellCommandline — so every single tick was a
 * brand-new PHP process. Nothing in-process could survive between ticks,
 * which meant every poll HAD to open a fresh RCON connection, and each
 * connection costs the operator two INFO lines in their own latest.log
 * ("Thread RCON Client /addr started" / "... shutting down").
 *
 * At four polls a minute that was ~11,500 lines/day. Measured on a live
 * Legendary (Paper) container it was 2,088 of 2,170 lines — 96% of the
 * entire log, 175 KB of 182 KB — and it pushed genuine content (plugin
 * enables, world load, player joins) out of CraftKeeper's own console
 * tail in about 75 seconds. Reusing the connection is the only fix that
 * addresses that at the source rather than hiding it, and it needs a
 * process that outlives a single poll. Hence this command.
 *
 * The same live container confirms the approach is sound: one socket ran
 * `list` seven times across 90 seconds with no idle timeout and no drop,
 * costing 2 log lines instead of 14. App\Console\MinecraftRconClient
 * handles the case where a held connection dies between polls (a server
 * restart, which CraftKeeper itself can trigger) by reconnecting and
 * retrying once.
 *
 * FAILURE POSTURE. A poll that fails is routine, not fatal: the sampler
 * records an unreachable App\Models\ServerSample, applies its jittered
 * backoff, and this loop continues. If the process dies anyway,
 * Supervisor restarts it, and in the gap App\Server\ServerStatusService
 * already degrades to "unavailable" on sample staleness rather than
 * reporting a fabricated zero — a dead poller fails visibly, never
 * silently.
 *
 * Dependencies are resolved via HANDLE-METHOD injection, deliberately NOT
 * the constructor, for the same reason SampleServerState documents:
 * Laravel's command auto-discovery instantiates every command class
 * eagerly just to register it by name — including during RefreshDatabase's
 * internal `migrate`, before the `settings` table the RCON binding reads
 * exists.
 */
class WatchServerState extends Command
{
    /**
     * @var string
     */
    protected $signature = 'server:watch
        {--max-cycles=0 : Stop after this many polls instead of running until signalled. 0 means run forever; anything else is for tests and manual diagnosis.}';

    /**
     * @var string
     */
    protected $description = 'Continuously poll lightweight RCON state (`list`) over a single long-lived connection, writing one ServerSample per poll.';

    /**
     * How long to wait between polls. This is the cadence the old
     * scheduled command ran at, preserved exactly — it is what
     * App\Server\ServerStatusService::SAMPLE_FRESHNESS_SECONDS (45s,
     * roughly 3x this) is calibrated against, so the two must move
     * together.
     */
    public const POLL_INTERVAL_SECONDS = 15;

    private bool $shouldQuit = false;

    public function handle(PersistentRconClient $client, ServerSampler $sampler): int
    {
        $maxCycles = (int) $this->option('max-cycles');

        if ($maxCycles < 0) {
            $this->error('--max-cycles must be 0 (run until signalled) or a positive number of polls.');

            return self::INVALID;
        }

        // Supervisor stops programs with SIGTERM. Trapping it lets the
        // current poll finish and the connection close cleanly, instead
        // of the socket dying mid-command and leaving the server to log
        // the disconnect on its own terms.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->shouldQuit = true;
        });

        $this->info('Watching server state over a persistent RCON connection.');

        try {
            for ($cycle = 0; ! $this->shouldQuit && ($maxCycles === 0 || $cycle < $maxCycles); $cycle++) {
                $sampler->sample($client);

                Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
            }
        } finally {
            // Runs on a normal stop, on SIGTERM, and if the loop throws —
            // the one connection this process opened is always the one
            // connection it closes.
            $client->disconnect();
        }

        return self::SUCCESS;
    }
}
