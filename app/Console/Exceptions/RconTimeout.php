<?php

namespace App\Console\Exceptions;

use RuntimeException;

/**
 * Either the 3-second connect budget or the 5-second read budget elapsed
 * without a terminal result (App\Console\MinecraftRconClient's separate
 * CONNECT_TIMEOUT_SECONDS / READ_TIMEOUT_SECONDS constants). $phase says
 * which one, since a caller polling RCON availability (e.g.
 * App\Operations\Handlers\ServerStopHandler's "wait for the server to
 * become unreachable, then healthy again" flow — Task 11's polling loop
 * itself) may want to treat "never even connected" differently from
 * "connected but never answered".
 */
class RconTimeout extends RuntimeException implements RconException
{
    public function __construct(
        public readonly string $phase,
        string $message,
    ) {
        parent::__construct($message);
    }
}
