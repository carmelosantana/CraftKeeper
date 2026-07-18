<?php

namespace App\Server;

/**
 * Jittered exponential backoff for App\Console\Commands\SampleServerState
 * (Task 11's ambiguity resolution #1: "retry backoff on failure has
 * jitter and a 60-second ceiling"). Implements the standard "full jitter"
 * strategy (delay = random(0, min(ceiling, base * 2^(failures-1)))) —
 * simple, well-known, and avoids every consecutive-failure retry landing
 * on exactly the same cadence.
 *
 * The random source is injectable so tests can assert exact boundary
 * values deterministically (0.0 -> the minimum possible delay for a given
 * failure count, 1.0 -> the maximum); production uses a real random
 * source by default.
 */
final class RetryBackoff
{
    /** No computed delay may ever exceed this, regardless of failure count. */
    public const CEILING_SECONDS = 60.0;

    private const BASE_SECONDS = 15.0;

    /** @var \Closure(): float */
    private readonly \Closure $random;

    /**
     * @param  (\Closure(): float)|null  $random  Returns a value in [0.0, 1.0). Defaults to a real random source.
     */
    public function __construct(?\Closure $random = null)
    {
        $this->random = $random ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    /**
     * The delay, in seconds, before the next attempt should be made,
     * given how many consecutive failures have occurred so far
     * (including the one that just happened). Always in
     * [0, CEILING_SECONDS] — never above the ceiling, no matter how large
     * $consecutiveFailures grows.
     */
    public function nextDelaySeconds(int $consecutiveFailures): float
    {
        $consecutiveFailures = max(1, $consecutiveFailures);

        // Cap the exponent itself, not just the final result, so the
        // intermediate value can never overflow to INF/NAN for a very
        // large failure count before the min() below would otherwise
        // clamp it.
        $exponent = min($consecutiveFailures - 1, 32);
        $uncapped = self::BASE_SECONDS * (2 ** $exponent);
        $capped = min($uncapped, self::CEILING_SECONDS);

        $randomFactor = ($this->random)();
        $randomFactor = min(max($randomFactor, 0.0), 1.0);

        return min($capped * $randomFactor, self::CEILING_SECONDS);
    }
}
