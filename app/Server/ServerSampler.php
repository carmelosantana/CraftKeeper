<?php

namespace App\Server;

use App\Console\Exceptions\RconException;
use App\Console\RconClient;
use App\Console\RconCommand;
use App\Models\ServerSample;
use Illuminate\Support\Facades\Cache;

/**
 * One attempt at the lightweight RCON health poll: run the SAFE `list`
 * command (Task 10's App\Console\CommandPolicy) and store one bounded
 * App\Models\ServerSample describing what happened.
 *
 * This is deliberately a service rather than logic living inside a
 * command, because two callers need it: App\Console\Commands\
 * WatchServerState (the long-running poll that supervisor actually runs)
 * and App\Console\Commands\SampleServerState (the one-shot, on-demand
 * equivalent an operator can run by hand). Both must produce identical
 * ServerSample rows so App\Server\ServerStatusService cannot tell which
 * one wrote the row it is reading.
 *
 * It talks to RconClient DIRECTLY — not through App\Operations\
 * OperationService/App\Console\RconCommandService — because a background
 * health poll is not a user-issued, audited command; it never proposes,
 * approves, or executes an Operation.
 *
 * Backoff (App\Server\RetryBackoff): a caller's polling cadence is a
 * CEILING on attempt frequency, not a guarantee every call reaches the
 * network. While backing off, sample() is a cheap Cache::get() no-op that
 * returns null — it does not attempt RCON, and does not insert a
 * ServerSample row, until the computed delay has elapsed. A successful
 * sample immediately resets the backoff state, so recovery is detected on
 * the very next call.
 */
final class ServerSampler
{
    private const CACHE_KEY_FAILURES = 'craftkeeper.server.rcon_sample.consecutive_failures';

    private const CACHE_KEY_NEXT_ATTEMPT_AT = 'craftkeeper.server.rcon_sample.next_attempt_at';

    public function __construct(
        private readonly RetryBackoff $backoff,
    ) {}

    /**
     * Attempt one sample. Returns the row that was written, or null when
     * this call was skipped because the backoff window has not elapsed —
     * null is "we deliberately did nothing", never "the server is down".
     */
    public function sample(RconClient $client): ?ServerSample
    {
        $now = now();

        /** @var string|null $nextAttemptAt */
        $nextAttemptAt = Cache::get(self::CACHE_KEY_NEXT_ATTEMPT_AT);

        if ($nextAttemptAt !== null && $now->lt($nextAttemptAt)) {
            return null;
        }

        try {
            $response = $client->execute(RconCommand::from('list'));
            [$count, $names] = $this->parseListResponse($response->body);

            $sample = ServerSample::query()->create([
                'sampled_at' => $now,
                'rcon_reachable' => true,
                'player_count' => $count,
                'player_names' => $names,
                'error_reason' => $count === null ? 'The server returned an unrecognized response to "list".' : null,
            ]);

            Cache::forget(self::CACHE_KEY_FAILURES);
            Cache::forget(self::CACHE_KEY_NEXT_ATTEMPT_AT);

            return $sample;
        } catch (RconException $e) {
            $failures = ((int) Cache::get(self::CACHE_KEY_FAILURES, 0)) + 1;
            Cache::put(self::CACHE_KEY_FAILURES, $failures);

            $delaySeconds = $this->backoff->nextDelaySeconds($failures);
            Cache::put(self::CACHE_KEY_NEXT_ATTEMPT_AT, $now->clone()->addSeconds($delaySeconds)->toIso8601String());

            return ServerSample::query()->create([
                'sampled_at' => $now,
                'rcon_reachable' => false,
                'player_count' => null,
                'player_names' => null,
                'error_reason' => $e->getMessage(),
            ]);
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
