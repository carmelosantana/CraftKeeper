<?php

namespace App\Console\Exceptions;

use RuntimeException;

/**
 * The accumulated body of a (possibly multi-packet) RCON response exceeded
 * App\Console\MinecraftRconClient's 1 MiB budget, OR the server sent more
 * individual response packets than MAX_RESPONSE_PACKETS allows for one
 * command (belt-and-suspenders against a flood of zero-body packets that
 * would never trip the byte budget on their own). Reading stops the
 * instant either bound is crossed — nothing beyond the limit is ever
 * buffered.
 */
class RconResponseTooLarge extends RuntimeException implements RconException
{
    public function __construct(
        public readonly int $accumulatedBytes,
        public readonly int $maxBytes,
        string $reason = 'accumulated response size',
    ) {
        parent::__construct(sprintf(
            'RCON response exceeded the %d byte limit (%s): %d bytes read so far.',
            $maxBytes,
            $reason,
            $accumulatedBytes,
        ));
    }
}
