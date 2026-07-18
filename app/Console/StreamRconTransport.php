<?php

namespace App\Console;

use App\Console\Exceptions\RconConnectionClosed;
use App\Console\Exceptions\RconTimeout;

/**
 * The real RconTransport: a plain TCP stream via stream_socket_client().
 * Deliberately thin — it does no protocol framing at all (that is
 * App\Console\MinecraftRconClient's job), only connect/read/write/close
 * against a socket, with the connect timeout enforced by
 * stream_socket_client()'s own $timeout argument and the read timeout
 * enforced via stream_set_timeout().
 *
 * Per Task 10's ambiguity resolution #1, this class's real socket I/O is
 * NOT exercised by the test suite (no test ever opens a real connection —
 * see tests/fixtures/rcon/FakeRconTransport.php, which every
 * MinecraftRconClient test injects instead). Its framing-relevant
 * behavior (partial reads, EOF, timeout signaling) is proven indirectly
 * by construction, since it implements the exact same RconTransport
 * contract the fake is built against and MinecraftRconClient never
 * branches on which implementation it was given.
 */
final class StreamRconTransport implements RconTransport
{
    /** @var resource|null */
    private $stream = null;

    public function connect(string $host, int $port, float $connectTimeoutSeconds, float $readTimeoutSeconds): void
    {
        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new RconTimeout('connect', "Could not connect to {$host}:{$port}: {$errstr} (errno {$errno}).");
        }

        $seconds = (int) floor($readTimeoutSeconds);
        $microseconds = (int) round(($readTimeoutSeconds - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, $microseconds);

        $this->stream = $stream;
    }

    public function write(string $bytes): void
    {
        if ($this->stream === null) {
            throw new RconConnectionClosed('Cannot write to RCON: not connected.');
        }

        $length = strlen($bytes);
        $written = 0;

        while ($written < $length) {
            $chunk = @fwrite($this->stream, substr($bytes, $written));

            if ($chunk === false || $chunk === 0) {
                throw new RconConnectionClosed('The RCON connection was closed while writing.');
            }

            $written += $chunk;
        }
    }

    public function read(int $maxLength): string
    {
        if ($this->stream === null) {
            throw new RconConnectionClosed('Cannot read from RCON: not connected.');
        }

        $bytes = @fread($this->stream, max(1, $maxLength));

        return $bytes === false ? '' : $bytes;
    }

    public function eof(): bool
    {
        return $this->stream === null || feof($this->stream);
    }

    public function timedOut(): bool
    {
        if ($this->stream === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->stream);

        return $meta['timed_out'];
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }
}
