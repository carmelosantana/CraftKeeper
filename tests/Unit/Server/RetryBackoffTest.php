<?php

use App\Server\RetryBackoff;

it('never exceeds the 60-second ceiling, even for very large failure counts, with random at its maximum', function () {
    $backoff = new RetryBackoff(fn () => 1.0);

    foreach ([1, 2, 3, 4, 5, 10, 20, 1000] as $failures) {
        expect($backoff->nextDelaySeconds($failures))->toBeLessThanOrEqual(RetryBackoff::CEILING_SECONDS);
    }

    // And it actually reaches the ceiling once the exponential growth
    // would otherwise exceed it (base 15 * 2^2 = 60 at failures=3).
    expect($backoff->nextDelaySeconds(3))->toBe(60.0)
        ->and($backoff->nextDelaySeconds(1000))->toBe(60.0);
});

it('never goes negative and stays at or below the ceiling with random at its minimum', function () {
    $backoff = new RetryBackoff(fn () => 0.0);

    foreach ([1, 2, 3, 10, 1000] as $failures) {
        $delay = $backoff->nextDelaySeconds($failures);
        expect($delay)->toBeGreaterThanOrEqual(0.0)
            ->and($delay)->toBeLessThanOrEqual(RetryBackoff::CEILING_SECONDS)
            ->toBe(0.0);
    }
});

it('grows exponentially with the failure count before hitting the ceiling', function () {
    $backoff = new RetryBackoff(fn () => 1.0);

    expect($backoff->nextDelaySeconds(1))->toBe(15.0)
        ->and($backoff->nextDelaySeconds(2))->toBe(30.0)
        ->and($backoff->nextDelaySeconds(3))->toBe(60.0);
});

it('applies jitter: two different random inputs at the same failure count produce different delays', function () {
    $low = new RetryBackoff(fn () => 0.25);
    $high = new RetryBackoff(fn () => 0.75);

    expect($low->nextDelaySeconds(2))->not->toBe($high->nextDelaySeconds(2));
});

it('treats a failure count below 1 the same as exactly 1 (no negative-exponent surprises)', function () {
    $backoff = new RetryBackoff(fn () => 1.0);

    expect($backoff->nextDelaySeconds(0))->toBe($backoff->nextDelaySeconds(1))
        ->and($backoff->nextDelaySeconds(-5))->toBe($backoff->nextDelaySeconds(1));
});

it('defaults to a real random source when none is injected, staying within bounds', function () {
    $backoff = new RetryBackoff;

    for ($i = 0; $i < 25; $i++) {
        $delay = $backoff->nextDelaySeconds(random_int(1, 50));
        expect($delay)->toBeGreaterThanOrEqual(0.0)
            ->and($delay)->toBeLessThanOrEqual(RetryBackoff::CEILING_SECONDS);
    }
});
