<?php

namespace App\Console;

/**
 * The (possibly reassembled from several wire packets) body text a
 * Minecraft server returned for one executed command.
 */
final class RconResponse
{
    public function __construct(
        public readonly string $body,
    ) {}
}
