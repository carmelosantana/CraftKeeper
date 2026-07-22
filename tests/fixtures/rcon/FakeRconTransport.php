<?php

namespace Tests\fixtures\rcon;

use App\Console\Exceptions\RconTimeout;
use App\Console\RconTransport;

/**
 * A fully in-memory RconTransport double. No socket, ever — every
 * MinecraftRconClient test in this suite is built on this fake (Task 10's
 * ambiguity resolution #1). Its namespace/directory casing
 * (Tests\fixtures\rcon / tests/fixtures/rcon) matches the brief's exact
 * required file path; PSR-4 autoloading is case-sensitive on Linux, so
 * the namespace segments must mirror the directory segments exactly.
 *
 * Construct with respondingWith() (a pre-built byte stream, typically
 * built from ::packet()) or connectTimesOut(). Chain ->inChunksOf() to
 * simulate a packet arriving fragmented across several transport-level
 * reads, or ->thenTimesOut() to simulate a read timeout once the buffered
 * bytes are exhausted (the default once exhausted is a clean EOF).
 *
 * $written records every raw byte string MinecraftRconClient wrote, in
 * order — tests use this both to assert on exact packet contents (e.g.
 * "the auth packet's body is the configured password") and, for
 * ServerStopHandlerTest, to prove "save-all flush" was sent strictly
 * before "stop".
 */
final class FakeRconTransport implements RconTransport
{
    /** @var list<string> */
    public array $written = [];

    public int $closeCalls = 0;

    public int $connectCalls = 0;

    /** @var list<string> */
    private array $subsequentBuffers = [];

    private bool $lastReadWasEof = false;

    private bool $lastReadWasTimeout = false;

    private bool $connectShouldTimeOut = false;

    private bool $timeOutAfterExhausted = false;

    private int $chunkSize = PHP_INT_MAX;

    private int $cursor = 0;

    private function __construct(
        private string $inbound,
    ) {}

    public static function respondingWith(string $bytes): self
    {
        return new self($bytes);
    }

    /**
     * Script one buffer PER CONNECTION: the first connect() serves
     * $first, and each subsequent connect() swaps in the next buffer and
     * rewinds the cursor. This is what makes a RECONNECT observably
     * different from a reused connection — a persistent client that
     * wrongly reconnects gets fresh bytes (and a fresh auth exchange),
     * while one that correctly holds the socket keeps reading forward
     * through the buffer it already has.
     */
    public static function respondingAcrossConnectionsWith(string $first, string ...$rest): self
    {
        $fake = new self($first);
        $fake->subsequentBuffers = array_values($rest);

        return $fake;
    }

    public static function connectTimesOut(): self
    {
        $fake = new self('');
        $fake->connectShouldTimeOut = true;

        return $fake;
    }

    /**
     * Deliver at most $bytes per read() call, forcing MinecraftRconClient
     * to reassemble a packet across multiple transport-level reads
     * (fragmentation).
     */
    public function inChunksOf(int $bytes): self
    {
        $this->chunkSize = max(1, $bytes);

        return $this;
    }

    /**
     * Once the buffered bytes are exhausted, read() reports a read
     * timeout instead of a clean EOF — simulates a server that accepted
     * the connection but never answers.
     */
    public function thenTimesOut(): self
    {
        $this->timeOutAfterExhausted = true;

        return $this;
    }

    /**
     * Build one raw Source RCON packet: int32-LE length, int32-LE request
     * id, int32-LE type, the body, then two NUL bytes.
     */
    public static function packet(int $requestId, int $type, string $body): string
    {
        $core = pack('V', $requestId & 0xFFFFFFFF).pack('V', $type & 0xFFFFFFFF).$body."\x00\x00";

        return pack('V', strlen($core)).$core;
    }

    public function connect(string $host, int $port, float $connectTimeoutSeconds, float $readTimeoutSeconds): void
    {
        if ($this->connectShouldTimeOut) {
            throw new RconTimeout('connect', "Simulated connect timeout to {$host}:{$port}.");
        }

        if ($this->connectCalls > 0 && $this->subsequentBuffers !== []) {
            $this->inbound = array_shift($this->subsequentBuffers);
            $this->cursor = 0;
            $this->lastReadWasEof = false;
            $this->lastReadWasTimeout = false;
        }

        $this->connectCalls++;
    }

    public function write(string $bytes): void
    {
        $this->written[] = $bytes;
    }

    public function read(int $maxLength): string
    {
        $this->lastReadWasEof = false;
        $this->lastReadWasTimeout = false;

        if ($this->cursor >= strlen($this->inbound)) {
            if ($this->timeOutAfterExhausted) {
                $this->lastReadWasTimeout = true;
            } else {
                $this->lastReadWasEof = true;
            }

            return '';
        }

        $take = min($maxLength, $this->chunkSize, strlen($this->inbound) - $this->cursor);
        $take = max($take, 1);

        $chunk = substr($this->inbound, $this->cursor, $take);
        $this->cursor += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->lastReadWasEof;
    }

    public function timedOut(): bool
    {
        return $this->lastReadWasTimeout;
    }

    public function close(): void
    {
        $this->closeCalls++;
    }
}
