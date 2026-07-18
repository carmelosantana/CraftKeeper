<?php

namespace App\Ai;

/**
 * The result of an App\Ai\AiProvider::health() check: whether the provider
 * responded within the bounded timeout (see App\Ai\AiManager — 2s
 * connect, 5s response, no request-path retries), how long the check
 * took, and — when unavailable — an honest, specific reason. Never
 * fabricates "available" from a stale or assumed state; a check is run
 * fresh every time health() is called.
 */
final readonly class AiProviderHealth
{
    private function __construct(
        public bool $available,
        public ?string $reason,
        public float $latencyMs,
    ) {}

    public static function up(float $latencyMs): self
    {
        return new self(true, null, $latencyMs);
    }

    public static function down(string $reason, float $latencyMs = 0.0): self
    {
        return new self(false, $reason, $latencyMs);
    }
}
