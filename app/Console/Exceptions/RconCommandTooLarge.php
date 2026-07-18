<?php

namespace App\Console\Exceptions;

use RuntimeException;

/**
 * A command's body exceeded App\Console\RconCommand::MAX_BODY_BYTES
 * (4 KiB). Refused at construction time, before anything is ever framed
 * into a packet or written to the transport.
 */
class RconCommandTooLarge extends RuntimeException implements RconException
{
    public function __construct(
        public readonly int $bytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct(sprintf(
            'RCON command body is %d bytes, exceeding the %d byte limit.',
            $bytes,
            $maxBytes,
        ));
    }
}
