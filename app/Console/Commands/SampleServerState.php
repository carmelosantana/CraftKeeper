<?php

namespace App\Console\Commands;

use App\Console\RconClient;
use App\Models\ServerSample;
use App\Server\ServerSampler;
use Illuminate\Console\Command;

/**
 * ONE on-demand RCON health sample, for an operator running it by hand
 * (or a support bundle capturing current state). All of the actual work —
 * the SAFE `list` command, parsing, the bounded App\Models\ServerSample
 * row, and the jittered backoff — lives in App\Server\ServerSampler, so
 * this and the continuous poller produce byte-identical rows.
 *
 * This is NOT what keeps App\Server\ServerStatusService supplied in a
 * running install; App\Console\Commands\WatchServerState is. This command
 * used to be scheduled every 15 seconds, but a scheduled command event is
 * a fresh `php artisan` process per tick, so it could never reuse an RCON
 * connection — and each connection costs the operator two INFO lines in
 * their own latest.log. See WatchServerState's docblock for the full
 * reasoning and the measurements. It is kept as a manual command because
 * "take one sample right now" is genuinely useful when diagnosing an
 * install, and it uses the ordinary connection-per-command RconClient
 * because a single sample has no connection to reuse.
 *
 * Dependencies are resolved via HANDLE-METHOD injection, deliberately NOT
 * the constructor: Laravel's `app/Console/Commands` auto-discovery
 * (bootstrap/app.php's default ->withCommands()) instantiates every
 * command class once, eagerly, just to register it by name — including
 * during RefreshDatabase's own internal `migrate` call, before the
 * `settings` table exists. A constructor-injected RconClient (whose
 * AppServiceProvider binding queries `settings` for the configured host)
 * would make EVERY test in the suite fail at that point, not just this
 * command's own tests. Method injection defers resolution to the moment
 * handle() actually runs, which is always after the app (and, in tests,
 * the database) is fully booted.
 */
class SampleServerState extends Command
{
    /**
     * @var string
     */
    protected $signature = 'server:sample-state';

    /**
     * @var string
     */
    protected $description = 'Take one RCON health sample (`list`) now and store a bounded ServerSample, respecting the shared backoff window.';

    public function handle(RconClient $client, ServerSampler $sampler): int
    {
        $sample = $sampler->sample($client);

        if (! $sample instanceof ServerSample) {
            $this->info('Skipping this sample: still within the RCON backoff window.');

            return self::SUCCESS;
        }

        if ($sample->rcon_reachable) {
            $this->info('RCON sample recorded.');
        } else {
            $this->warn("RCON unreachable: {$sample->error_reason}");
        }

        // A health poll degrading is expected, routine operation, not a
        // command failure — never returns a non-zero exit that would spam
        // scheduler/supervisor logs.
        return self::SUCCESS;
    }
}
