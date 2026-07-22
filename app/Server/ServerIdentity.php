<?php

namespace App\Server;

/**
 * The few facts about the Minecraft server that come from its own
 * `server.properties` rather than from a live RCON sample: what the server
 * calls itself, and how many players it is configured to admit.
 *
 * Every field is nullable and null means UNKNOWN, never a default. There is
 * deliberately no "20" fallback for $maxPlayers even though that is
 * Minecraft's own default: an operator who has never been asked would be
 * shown a number CraftKeeper invented, which is the same class of bug this
 * value object exists to end (see App\Server\RconStatus's "no fabricated
 * zero" rule, which this mirrors for configuration rather than telemetry).
 */
final readonly class ServerIdentity
{
    public function __construct(
        public ?string $motd,
        public ?int $maxPlayers,
    ) {}

    public static function unknown(): self
    {
        return new self(motd: null, maxPlayers: null);
    }
}
