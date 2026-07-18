<?php

namespace App\Console\Exceptions;

use RuntimeException;

/**
 * A packet received from (or about to be sent to) the RCON server does not
 * conform to the Source RCON wire format: an invalid/oversized length
 * header, a malformed body terminator, an unexpected packet type, or a
 * response whose request id matches neither the in-flight command nor its
 * terminator. Thrown BEFORE any allocation/read sized by attacker-supplied
 * data — see App\Console\MinecraftRconClient::readPacket()'s length check,
 * which runs against the raw 4-byte header before ever attempting to read
 * the (possibly hostile) declared body length.
 */
class InvalidRconPacket extends RuntimeException implements RconException {}
