<?php

use App\Console\Exceptions\InvalidRconPacket;
use App\Console\Exceptions\RconAuthFailed;
use App\Console\Exceptions\RconConnectionClosed;
use App\Console\Exceptions\RconResponseTooLarge;
use App\Console\Exceptions\RconTimeout;
use App\Console\MinecraftRconClient;
use App\Console\RconCommand;
use Tests\fixtures\rcon\FakeRconTransport;

/*
|--------------------------------------------------------------------------
| The brief's verbatim malformed/oversized packet test
|--------------------------------------------------------------------------
*/

it('rejects malformed and oversized RCON packets', function () {
    $transport = FakeRconTransport::respondingWith(pack('V', 99_999_999));

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(InvalidRconPacket::class);
});

/*
|--------------------------------------------------------------------------
| Malformed packets, precisely
|--------------------------------------------------------------------------
*/

it('throws InvalidRconPacket for a packet length below the protocol minimum', function () {
    // requestId(4) + type(4) + "" + NUL + NUL = 10 is the floor; 5 can
    // never contain a legal packet.
    $transport = FakeRconTransport::respondingWith(pack('V', 5));

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(InvalidRconPacket::class);
});

it('throws InvalidRconPacket when a packet body is not NUL-terminated', function () {
    $core = pack('V', 1).pack('V', 0).'XY';
    $packet = pack('V', strlen($core)).$core;
    $transport = FakeRconTransport::respondingWith($packet);

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(InvalidRconPacket::class);
});

it('throws InvalidRconPacket when a response packet id matches neither the command nor its terminator', function () {
    $bytes = FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(42, 0, 'unexpected');
    $transport = FakeRconTransport::respondingWith($bytes);

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(InvalidRconPacket::class);
});

it('throws InvalidRconPacket when a response packet has an unexpected type', function () {
    $bytes = FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 99, 'x');
    $transport = FakeRconTransport::respondingWith($bytes);

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(InvalidRconPacket::class);
});

it('throws InvalidRconPacket when the auth response id is neither the auth id nor -1', function () {
    $bytes = FakeRconTransport::packet(99, 0, '');
    $transport = FakeRconTransport::respondingWith($bytes);

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(InvalidRconPacket::class);
});

/*
|--------------------------------------------------------------------------
| Fragmentation and multi-packet responses
|--------------------------------------------------------------------------
*/

it('reassembles a valid packet delivered in fragments across multiple transport reads', function () {
    $bytes = FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 0, 'hello world')
        .FakeRconTransport::packet(3, 0, '');
    $transport = FakeRconTransport::respondingWith($bytes)->inChunksOf(3);

    $response = (new MinecraftRconClient($transport))->execute(RconCommand::from('list'));

    expect($response->body)->toBe('hello world');
});

it('reassembles a multi-packet response (split by the server) into one RconResponse', function () {
    $bytes = FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 0, 'Steve, ')
        .FakeRconTransport::packet(2, 0, 'Alex, Bob')
        .FakeRconTransport::packet(3, 0, '');
    $transport = FakeRconTransport::respondingWith($bytes);

    $response = (new MinecraftRconClient($transport))->execute(RconCommand::from('list'));

    expect($response->body)->toBe('Steve, Alex, Bob');
});

it('reassembles a multi-packet response that is ALSO fragmented at the transport level', function () {
    $bytes = FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 0, 'part-one-')
        .FakeRconTransport::packet(2, 0, 'part-two')
        .FakeRconTransport::packet(3, 0, '');
    $transport = FakeRconTransport::respondingWith($bytes)->inChunksOf(5);

    $response = (new MinecraftRconClient($transport))->execute(RconCommand::from('list'));

    expect($response->body)->toBe('part-one-part-two');
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

it('throws RconAuthFailed when the auth response request id is -1', function () {
    $bytes = FakeRconTransport::packet(-1, 0, '');
    $transport = FakeRconTransport::respondingWith($bytes);

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(RconAuthFailed::class);
});

it('sends the configured host-independent password in the auth packet body', function () {
    $bytes = FakeRconTransport::packet(1, 0, '').FakeRconTransport::packet(3, 0, '');
    $transport = FakeRconTransport::respondingWith($bytes);

    (new MinecraftRconClient($transport, '10.0.0.5', 25577, 's3cret-pw'))->execute(RconCommand::from('list'));

    $authPacket = $transport->written[0];
    $body = substr($authPacket, 12, strlen($authPacket) - 12 - 2);

    expect($body)->toBe('s3cret-pw')
        ->and(substr($authPacket, 8, 4))->toBe(pack('V', 3)); // type = auth
});

/*
|--------------------------------------------------------------------------
| Timeouts and connection loss — distinct typed failures
|--------------------------------------------------------------------------
*/

it('throws RconTimeout with phase "connect" when the transport cannot connect within budget', function () {
    $transport = FakeRconTransport::connectTimesOut();

    try {
        (new MinecraftRconClient($transport))->execute(RconCommand::from('list'));
        test()->fail('Expected RconTimeout to be thrown.');
    } catch (RconTimeout $e) {
        expect($e->phase)->toBe('connect');
    }
});

it('throws RconTimeout with phase "read" when no data arrives before the read timeout', function () {
    $transport = FakeRconTransport::respondingWith('')->thenTimesOut();

    try {
        (new MinecraftRconClient($transport))->execute(RconCommand::from('list'));
        test()->fail('Expected RconTimeout to be thrown.');
    } catch (RconTimeout $e) {
        expect($e->phase)->toBe('read');
    }
});

it('throws RconConnectionClosed when the connection reaches EOF before a full packet is read', function () {
    $transport = FakeRconTransport::respondingWith('');

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(RconConnectionClosed::class);
});

it('throws RconConnectionClosed when EOF occurs mid-packet (header read but body cut short)', function () {
    // A complete, valid auth response, then a length header for a body
    // that never actually arrives.
    $bytes = FakeRconTransport::packet(1, 0, '').pack('V', 20);
    $transport = FakeRconTransport::respondingWith($bytes);

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(RconConnectionClosed::class);
});

/*
|--------------------------------------------------------------------------
| Bounded reads: the accumulated response budget
|--------------------------------------------------------------------------
*/

it('throws RconResponseTooLarge when the accumulated multi-packet response exceeds the 1 MiB budget', function () {
    $chunk = str_repeat('x', 600_000);
    $bytes = FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 0, $chunk)
        .FakeRconTransport::packet(2, 0, $chunk);
    $transport = FakeRconTransport::respondingWith($bytes);

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(RconResponseTooLarge::class);
});

it('throws RconResponseTooLarge when the server floods more response packets than the per-command limit', function () {
    $bytes = FakeRconTransport::packet(1, 0, '');

    for ($i = 0; $i < 10_001; $i++) {
        $bytes .= FakeRconTransport::packet(2, 0, '');
    }

    $transport = FakeRconTransport::respondingWith($bytes);

    expect(fn () => (new MinecraftRconClient($transport))->execute(RconCommand::from('list')))
        ->toThrow(RconResponseTooLarge::class);
});

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

it('returns the full response body on a successful single-packet command', function () {
    $bytes = FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 0, 'There are 1 of a max of 20 players online: Steve')
        .FakeRconTransport::packet(3, 0, '');
    $transport = FakeRconTransport::respondingWith($bytes);

    $response = (new MinecraftRconClient($transport))->execute(RconCommand::from('list'));

    expect($response->body)->toBe('There are 1 of a max of 20 players online: Steve');
});

it('always closes the transport exactly once, even when execute() throws', function () {
    $transport = FakeRconTransport::respondingWith(pack('V', 99_999_999));

    try {
        (new MinecraftRconClient($transport))->execute(RconCommand::from('list'));
    } catch (InvalidRconPacket) {
        // expected
    }

    expect($transport->closeCalls)->toBe(1);
});

it('closes the transport exactly once on a successful execute() too', function () {
    $bytes = FakeRconTransport::packet(1, 0, '').FakeRconTransport::packet(3, 0, '');
    $transport = FakeRconTransport::respondingWith($bytes);

    (new MinecraftRconClient($transport))->execute(RconCommand::from('list'));

    expect($transport->closeCalls)->toBe(1);
});
