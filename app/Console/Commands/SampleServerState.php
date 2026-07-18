<?php

namespace App\Console\Commands;

use App\Console\Exceptions\RconException;
use App\Console\RconClient;
use App\Console\RconCommand;
use App\Models\ServerSample;
use App\Server\RetryBackoff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Polls lightweight RCON state (the SAFE `list` command — Task 10's
 * App\Console\CommandPolicy) on a schedule (every 15 seconds while
 * reachable, wired via ->withSchedule() in bootstrap/app.php — Task 11's
 * ambiguity resolution #1) and stores one bounded App\Models\ServerSample
 * per actual attempt.
 *
 * This talks to RconClient DIRECTLY — not through App\Operations\
 * OperationService/App\Console\RconCommandService — because a background
 * health poll is not a user-issued, audited command; it never proposes,
 * approves, or executes an Operation. This mirrors the exact pattern
 * App\Operations\Handlers\ServerStopHandler's own docblock anticipates
 * ("the health-poll loop ... Task 11's surface").
 *
 * Backoff (App\Server\RetryBackoff): every 15-second tick is a CEILING on
 * attempt frequency, not a guarantee every tick calls out over the
 * network. While backing off, this command is a cheap Cache::get() no-op
 * — it does not attempt RCON again, and does not insert a new
 * ServerSample row, until the computed delay has elapsed. A successful
 * sample immediately resets the backoff state, so recovery is detected on
 * the very next tick.
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
    protected $description = 'Poll lightweight RCON state (`list`) and store a bounded ServerSample, backing off (with jitter, up to a 60s ceiling) while RCON is unreachable.';

    private const CACHE_KEY_FAILURES = 'craftkeeper.server.rcon_sample.consecutive_failures';

    private const CACHE_KEY_NEXT_ATTEMPT_AT = 'craftkeeper.server.rcon_sample.next_attempt_at';

    public function handle(RconClient $client, RetryBackoff $backoff): int
    {
        $now = now();

        /** @var string|null $nextAttemptAt */
        $nextAttemptAt = Cache::get(self::CACHE_KEY_NEXT_ATTEMPT_AT);

        if ($nextAttemptAt !== null && $now->lt($nextAttemptAt)) {
            $this->info("Skipping this tick: backing off until {$nextAttemptAt}.");

            return self::SUCCESS;
        }

        try {
            $response = $client->execute(RconCommand::from('list'));
            [$count, $names] = $this->parseListResponse($response->body);

            ServerSample::query()->create([
                'sampled_at' => $now,
                'rcon_reachable' => true,
                'player_count' => $count,
                'player_names' => $names,
                'error_reason' => $count === null ? 'The server returned an unrecognized response to "list".' : null,
            ]);

            Cache::forget(self::CACHE_KEY_FAILURES);
            Cache::forget(self::CACHE_KEY_NEXT_ATTEMPT_AT);

            $this->info('RCON sample recorded.');

            return self::SUCCESS;
        } catch (RconException $e) {
            $failures = ((int) Cache::get(self::CACHE_KEY_FAILURES, 0)) + 1;
            Cache::put(self::CACHE_KEY_FAILURES, $failures);

            $delaySeconds = $backoff->nextDelaySeconds($failures);
            Cache::put(self::CACHE_KEY_NEXT_ATTEMPT_AT, $now->clone()->addSeconds($delaySeconds)->toIso8601String());

            ServerSample::query()->create([
                'sampled_at' => $now,
                'rcon_reachable' => false,
                'player_count' => null,
                'player_names' => null,
                'error_reason' => $e->getMessage(),
            ]);

            $this->warn("RCON unreachable: {$e->getMessage()}");

            // A scheduled health poll degrading is expected, routine
            // operation, not a command failure — never throws, never
            // returns a non-zero exit that would spam scheduler logs.
            return self::SUCCESS;
        }
    }

    /**
     * Parses the vanilla/Paper `list` response, e.g. "There are 2 of a
     * max of 20 players online: Alice, Bob" (or "...online:" with none).
     * Returns [null, null] — never a fabricated 0 — when the response
     * doesn't match the expected shape at all.
     *
     * @return array{0: int|null, 1: list<string>|null}
     */
    private function parseListResponse(string $body): array
    {
        if (preg_match('/^There are (\d+) of a max(?:imum)? of \d+ players online:?\s*(.*)$/i', trim($body), $matches) !== 1) {
            return [null, null];
        }

        $count = (int) $matches[1];
        $namesPart = trim($matches[2]);
        $names = $namesPart === '' ? [] : array_map('trim', explode(',', $namesPart));

        return [$count, $names];
    }
}
