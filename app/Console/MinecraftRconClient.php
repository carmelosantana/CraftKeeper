<?php

namespace App\Console;

use App\Console\Exceptions\InvalidRconPacket;
use App\Console\Exceptions\RconAuthFailed;
use App\Console\Exceptions\RconConnectionClosed;
use App\Console\Exceptions\RconException;
use App\Console\Exceptions\RconResponseTooLarge;
use App\Console\Exceptions\RconTimeout;

/**
 * A bounded Minecraft (Source) RCON client. One execute() call is
 * connect -> authenticate -> send the command (plus a trailing empty
 * "terminator" command) -> read every response packet until the
 * terminator's own response comes back -> close. This is the one and only
 * implementation of RconClient.
 *
 * CONNECTION LIFETIME. By default each execute() is a fresh, short-lived
 * connection, which is right for the rare, user-issued, audited commands
 * (App\Operations\Handlers\RconCommandHandler, ServerStopHandler).
 * Constructed with `persistent: true`, the client instead holds ONE
 * authenticated connection open across many execute() calls, and only
 * App\Console\Commands\WatchServerState — the long-running health poll —
 * asks for that.
 *
 * The reason is the OPERATOR'S log, not throughput. Minecraft writes two
 * INFO lines into latest.log for every RCON connection it ACCEPTS
 * ("Thread RCON Client /addr started" / "... shutting down") — never one
 * per command. A connect-per-poll health sampler therefore wrote ~11,500
 * lines/day into the user's own server log; measured against a live
 * Legendary (Paper) container it was 96% of the entire file, and it
 * pushed genuine content out of CraftKeeper's console tail within ~75
 * seconds. The same container confirms holding the socket is safe: one
 * connection ran `list` seven times across 90 seconds with no idle
 * timeout and no drop, costing 2 log lines instead of 14.
 *
 * A held connection can still die between commands while it sits idle —
 * most importantly when the Minecraft server restarts, which CraftKeeper
 * itself can trigger. The write may even succeed into a local socket
 * buffer, so the loss only surfaces on the read. execute() therefore
 * reconnects and retries EXACTLY ONCE when a command fails on a
 * connection that was opened for an earlier command, and never retries a
 * command that failed on a connection it just opened (which would double
 * every attempt against a server that is simply down). A failed execute()
 * always leaves the client disconnected, so the next call starts clean
 * rather than latching into a broken state.
 *
 * Wire format (Task 10's ambiguity resolution #2): int32-LE length,
 * int32-LE request id, int32-LE type, a NUL-terminated body, then one
 * more empty NUL. Types: auth=3, exec=2, response=0 (sent by the server
 * only). Auth failure is signaled by a response whose request id is -1 —
 * never a distinguishable "packet shape", so it must be checked
 * explicitly (see authenticate()). A successful auth reply's TYPE is
 * deliberately never checked: real Source/Minecraft RCON servers reply
 * to a successful SERVERDATA_AUTH with type 2 (SERVERDATA_AUTH_RESPONSE),
 * while this class's own command-response packets use type 0 — mainstream
 * RCON clients accept either on the auth reply and gate success on the
 * request id alone, and authenticate() does the same.
 *
 * Multi-packet responses: Source RCON gives no way to tell from a single
 * packet whether more are coming, so — following the standard workaround
 * every mainstream RCON client uses — execute() sends a second, empty
 * exec packet immediately after the real command, using a distinct
 * request id (TERMINATOR_REQUEST_ID). Response packets carrying the
 * command's own id are accumulated; the response carrying the
 * terminator's id is the reliable "no more fragments are coming" signal
 * (its body, if any, is discarded). A response packet whose id is
 * neither of those two is a protocol violation (request-id mismatch) and
 * throws InvalidRconPacket rather than being silently ignored or
 * misattributed.
 *
 * Every request id is fixed per connection (auth=1, command=2,
 * terminator=3) rather than randomly generated. This stays safe when a
 * connection is REUSED because execute() is strictly synchronous — it
 * reads its own command's response through to the terminator before
 * returning, so there is never more than one command in flight on a
 * connection regardless of how many commands that connection goes on to
 * carry. Fixed ids also make every test in this suite fully
 * deterministic.
 *
 * Why a hostile length header can never over-read or hang (see
 * readPacket()/readExactly() below): the 4-byte length header is decoded
 * and range-checked (10..MAX_PACKET_LENGTH) BEFORE any attempt is made to
 * read the bytes it claims follow — an oversized or negative-looking
 * value (e.g. pack('V', 99_999_999)) is rejected immediately, with zero
 * bytes allocated or read beyond the 4-byte header itself. readExactly()
 * only ever grows its buffer by however many bytes the transport actually
 * produced in one call (never more than requested), and on every
 * empty-string read it consults the transport's own eof()/timedOut()
 * flags and throws a typed, terminal exception rather than looping again
 * — so it can only ever iterate a bounded number of times (bounded by the
 * number of bytes still needed) before either completing or throwing.
 */
final class MinecraftRconClient implements PersistentRconClient
{
    public const CONNECT_TIMEOUT_SECONDS = 3.0;

    public const READ_TIMEOUT_SECONDS = 5.0;

    /** Minimum legal packet length: requestId(4) + type(4) + "" + NUL + NUL. */
    private const MIN_PACKET_LENGTH = 10;

    /**
     * A single packet's declared length can never legitimately exceed the
     * whole-response budget below — anything bigger is definitionally
     * corrupt or hostile and is rejected before it is ever read.
     */
    private const MAX_PACKET_LENGTH = 1_048_576;

    /** Accumulated response budget across every fragment of one command's response. */
    private const MAX_RESPONSE_BYTES = 1_048_576;

    /**
     * Belt-and-suspenders against a server (malicious or buggy) that
     * floods zero-body response packets forever, which would never trip
     * the byte budget above on its own.
     */
    private const MAX_RESPONSE_PACKETS = 10_000;

    private const TYPE_RESPONSE = 0;

    private const TYPE_EXEC = 2;

    private const TYPE_AUTH = 3;

    private const AUTH_REQUEST_ID = 1;

    private const COMMAND_REQUEST_ID = 2;

    private const TERMINATOR_REQUEST_ID = 3;

    /**
     * True once connect() + authenticate() have both succeeded, and false
     * again the moment the connection is closed. Only ever true between
     * execute() calls in persistent mode.
     */
    private bool $connected = false;

    public function __construct(
        private readonly RconTransport $transport,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 25575,
        private readonly string $password = '',
        private readonly bool $persistent = false,
    ) {}

    public function execute(RconCommand $command): RconResponse
    {
        if ($this->persistent) {
            return $this->executeOnHeldConnection($command);
        }

        $this->connect();

        try {
            $this->authenticate();

            return $this->runCommand($command);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Release the held connection, if any. Safe to call when nothing was
     * ever connected, and safe to call twice — the long-running poll
     * calls it on shutdown so a supervisor restart never leaves a socket
     * (and its two log lines) dangling.
     */
    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        $this->connected = false;
        $this->transport->close();
    }

    private function executeOnHeldConnection(RconCommand $command): RconResponse
    {
        // A connection opened for an EARLIER command may have gone away
        // while it sat idle (a server restart is the common case), and
        // there is no way to know that without using it. One reconnect,
        // one retry — see the class docblock.
        if ($this->connected) {
            try {
                return $this->runCommand($command);
            } catch (RconException) {
                $this->disconnect();
            }
        }

        try {
            $this->connect();
            $this->authenticate();

            return $this->runCommand($command);
        } catch (RconException $e) {
            $this->disconnect();

            throw $e;
        }
    }

    private function connect(): void
    {
        $this->transport->connect(
            $this->host,
            $this->port,
            self::CONNECT_TIMEOUT_SECONDS,
            self::READ_TIMEOUT_SECONDS,
        );

        $this->connected = true;
    }

    private function runCommand(RconCommand $command): RconResponse
    {
        $this->sendPacket(self::COMMAND_REQUEST_ID, self::TYPE_EXEC, $command->body);
        $this->sendPacket(self::TERMINATOR_REQUEST_ID, self::TYPE_EXEC, '');

        return new RconResponse($this->readCommandResponse());
    }

    private function authenticate(): void
    {
        $this->sendPacket(self::AUTH_REQUEST_ID, self::TYPE_AUTH, $this->password);

        $packet = $this->readPacket();

        if ($packet['requestId'] === -1) {
            throw new RconAuthFailed('RCON authentication failed: the server rejected the configured password.');
        }

        // Success is gated on the request id alone, NOT the packet type. A
        // real Source/Minecraft RCON server answers a successful auth with
        // type 2 (SERVERDATA_AUTH_RESPONSE), not type 0 — requiring type 0
        // here would reject every successful login from a real server.
        // Mainstream RCON clients (mcrcon, etc.) do the same: check the id,
        // ignore the type. Both TYPE_RESPONSE (0) and TYPE_AUTH_RESPONSE (2)
        // are therefore accepted as long as the id matches what we sent.
        if ($packet['requestId'] !== self::AUTH_REQUEST_ID) {
            throw new InvalidRconPacket("Received an unexpected RCON auth response (type {$packet['type']}, id {$packet['requestId']}).");
        }
    }

    private function readCommandResponse(): string
    {
        $accumulated = '';
        $packetCount = 0;

        while (true) {
            if (++$packetCount > self::MAX_RESPONSE_PACKETS) {
                throw new RconResponseTooLarge(strlen($accumulated), self::MAX_RESPONSE_BYTES, 'too many response packets');
            }

            $packet = $this->readPacket();

            if ($packet['type'] !== self::TYPE_RESPONSE) {
                throw new InvalidRconPacket("Received an unexpected RCON packet type ({$packet['type']}) while reading a command response.");
            }

            if ($packet['requestId'] === self::TERMINATOR_REQUEST_ID) {
                break;
            }

            if ($packet['requestId'] !== self::COMMAND_REQUEST_ID) {
                throw new InvalidRconPacket("Received an RCON response with a mismatched request id ({$packet['requestId']}).");
            }

            $accumulated .= $packet['body'];

            if (strlen($accumulated) > self::MAX_RESPONSE_BYTES) {
                throw new RconResponseTooLarge(strlen($accumulated), self::MAX_RESPONSE_BYTES);
            }
        }

        return $accumulated;
    }

    private function sendPacket(int $requestId, int $type, string $body): void
    {
        $core = $this->packInt32LE($requestId).$this->packInt32LE($type).$body."\x00\x00";

        $this->transport->write($this->packInt32LE(strlen($core)).$core);
    }

    /**
     * Read one full packet, reassembling it from as many transport-level
     * reads as necessary (see the class docblock for why this can never
     * over-read or hang). The length header is validated before the body
     * is ever read.
     *
     * @return array{requestId: int, type: int, body: string}
     */
    private function readPacket(): array
    {
        $length = $this->unpackInt32LE($this->readExactly(4));

        if ($length < self::MIN_PACKET_LENGTH || $length > self::MAX_PACKET_LENGTH) {
            throw new InvalidRconPacket("Received an RCON packet with an invalid length header ({$length} bytes).");
        }

        $rest = $this->readExactly($length);

        if (substr($rest, -2) !== "\x00\x00") {
            throw new InvalidRconPacket('Received an RCON packet with a malformed body terminator.');
        }

        return [
            'requestId' => $this->unpackInt32LE(substr($rest, 0, 4)),
            'type' => $this->unpackInt32LE(substr($rest, 4, 4)),
            'body' => substr($rest, 8, $length - 10),
        ];
    }

    /**
     * Accumulate exactly $length bytes from the transport, looping over
     * as many partial (fragmented) reads as the transport produces. Each
     * iteration either appends at least one byte (guaranteed progress
     * toward the loop's exit condition) or throws immediately on the
     * transport's own terminal signal (timeout or EOF) — there is no path
     * that can spin without making progress or throwing.
     */
    private function readExactly(int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = $this->transport->read($length - strlen($buffer));

            if ($chunk === '') {
                if ($this->transport->timedOut()) {
                    throw new RconTimeout('read', 'Timed out waiting for an RCON response.');
                }

                throw new RconConnectionClosed('The RCON connection was closed before a full packet could be read.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function packInt32LE(int $value): string
    {
        return pack('V', $value & 0xFFFFFFFF);
    }

    private function unpackInt32LE(string $bytes): int
    {
        $result = unpack('V', $bytes);

        if ($result === false) {
            throw new InvalidRconPacket('Failed to decode a 4-byte integer from the RCON stream.');
        }

        $unsigned = $result[1];

        return $unsigned > 0x7FFFFFFF ? $unsigned - 0x100000000 : $unsigned;
    }
}
