<?php

namespace App\Console;

use App\Console\Exceptions\InvalidRconPacket;
use App\Console\Exceptions\RconCommandTooLarge;

/**
 * A validated, wire-ready RCON command body. Construction is the one and
 * only gate a command string passes through before it can ever be framed
 * into a packet: empty, NUL-containing, and over-budget input are all
 * refused here, before App\Console\MinecraftRconClient ever writes a byte
 * to a transport.
 */
final class RconCommand
{
    /**
     * Source RCON packet bodies are NUL-terminated strings; an embedded
     * NUL byte would silently truncate the command as the server parses
     * it, so it is refused outright rather than sent partially.
     */
    public const MAX_BODY_BYTES = 4096;

    private function __construct(
        public readonly string $body,
    ) {}

    public static function from(string $command): self
    {
        if ($command === '') {
            throw new InvalidRconPacket('RCON command body must not be empty.');
        }

        if (str_contains($command, "\0")) {
            throw new InvalidRconPacket('RCON command body must not contain an embedded NUL byte.');
        }

        $bytes = strlen($command);

        if ($bytes > self::MAX_BODY_BYTES) {
            throw new RconCommandTooLarge($bytes, self::MAX_BODY_BYTES);
        }

        return new self($command);
    }
}
