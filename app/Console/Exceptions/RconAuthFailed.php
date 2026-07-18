<?php

namespace App\Console\Exceptions;

use RuntimeException;

/**
 * The RCON server rejected authentication: its response to the
 * SERVERDATA_AUTH packet carried a request id of -1, which the Source
 * RCON protocol reserves specifically to signal "authentication failed"
 * (never a legitimate exec/response id). The message deliberately never
 * echoes the password that was attempted.
 */
class RconAuthFailed extends RuntimeException implements RconException {}
