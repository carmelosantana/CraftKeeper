<?php

namespace App\Console;

/**
 * The raw byte-transport seam beneath App\Console\MinecraftRconClient.
 * Deliberately modeled on PHP stream semantics (fread()/feof()/
 * stream_get_meta_data()['timed_out']) rather than "read exactly N bytes
 * or throw", because that is exactly what App\Console\StreamRconTransport
 * wraps, and it is what lets tests\fixtures\rcon\FakeRconTransport
 * simulate fragmentation (deliver fewer bytes than requested, forcing the
 * client to loop) and both terminal conditions (EOF vs. read timeout)
 * without ever touching a real socket. MinecraftRconClient owns ALL
 * protocol framing (packet length prefix, request id, type, NUL
 * terminators) and every size/timeout bound — this interface only moves
 * bytes.
 *
 * Contract for implementations:
 * - connect() must throw App\Console\Exceptions\RconTimeout (phase
 *   'connect') if a connection cannot be established within
 *   $connectTimeoutSeconds, and must arrange for read() to honor
 *   $readTimeoutSeconds afterward.
 * - read($maxLength) may return FEWER bytes than requested (a partial /
 *   fragmented read) but must not return an empty string unless the read
 *   timeout has elapsed or the connection is at EOF — a well-behaved
 *   implementation always blocks (within budget) until at least one byte
 *   or a terminal condition occurs, so callers never busy-loop on '' with
 *   neither eof() nor timedOut() true.
 * - eof() / timedOut() describe the OUTCOME of the most recent read()
 *   call only, exactly like PHP's own feof()/stream_get_meta_data().
 */
interface RconTransport
{
    public function connect(string $host, int $port, float $connectTimeoutSeconds, float $readTimeoutSeconds): void;

    public function write(string $bytes): void;

    /**
     * Read up to $maxLength bytes right now. May return a partial chunk.
     * Returns '' when no bytes could be produced — check eof()/timedOut()
     * immediately afterward to find out why.
     */
    public function read(int $maxLength): string;

    /**
     * Whether the connection was found closed during the most recent
     * read() call.
     */
    public function eof(): bool;

    /**
     * Whether the configured read timeout elapsed during the most recent
     * read() call.
     */
    public function timedOut(): bool;

    public function close(): void;
}
