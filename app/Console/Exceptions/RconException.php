<?php

namespace App\Console\Exceptions;

use Throwable;

/**
 * Marker interface implemented by every typed RCON protocol failure
 * (InvalidRconPacket, RconTimeout, RconAuthFailed, RconResponseTooLarge,
 * RconConnectionClosed, RconCommandTooLarge). Callers that only care
 * whether *something* went wrong talking to RCON can catch this one
 * interface; callers that need to react differently per failure (e.g.
 * App\Operations\Handlers\RconCommandHandler mapping to a stable error
 * code) still match on the concrete class. Never thrown directly.
 */
interface RconException extends Throwable {}
