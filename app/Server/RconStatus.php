<?php

namespace App\Server;

use Carbon\CarbonInterface;

/**
 * The RCON-dependent half of a App\Server\ServerStatusSnapshot. When
 * $available is false, $playerCount/$playerNames are ALWAYS null — never
 * a fabricated 0/empty list (Task 11's ambiguity resolution #5: "Unknown
 * values display/return 'Unavailable' with a reason — never a fabricated
 * zero"). A genuinely observed zero (RCON reachable, `list` reports 0
 * players online) is represented as $playerCount === 0 with $available
 * true — a real, known value, distinct from "we don't know".
 */
final readonly class RconStatus
{
    /**
     * @param  list<string>|null  $playerNames
     */
    private function __construct(
        public bool $available,
        public ?string $reason,
        public ?int $playerCount,
        public ?array $playerNames,
        public ?CarbonInterface $sampledAt,
    ) {}

    /**
     * @param  list<string>|null  $playerNames
     */
    public static function available(?int $playerCount, ?array $playerNames, CarbonInterface $sampledAt): self
    {
        return new self(true, null, $playerCount, $playerNames, $sampledAt);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, null, null, null);
    }
}
