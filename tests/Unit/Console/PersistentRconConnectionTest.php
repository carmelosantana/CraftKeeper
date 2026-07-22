<?php

use App\Console\Exceptions\RconAuthFailed;
use App\Console\Exceptions\RconConnectionClosed;
use App\Console\MinecraftRconClient;
use App\Console\RconCommand;
use Tests\fixtures\rcon\FakeRconTransport;

/*
|--------------------------------------------------------------------------
| Persistent connections
|--------------------------------------------------------------------------
|
| Minecraft writes TWO INFO lines into the operator's own latest.log for
| every RCON connection it ACCEPTS ("Thread RCON Client /addr started" and
| "... shutting down") — never one per command. Measured against a live
| Legendary (Paper) container, the connect-per-poll health sampler was
| therefore producing ~11,500 lines/day and accounted for 96% of the whole
| server log, pushing genuine content (plugin enables, world load, player
| joins) out of the console tail within ~75 seconds.
|
| Holding ONE connection open across many commands is what removes that
| noise at the source, and the same live container confirms it is safe: a
| single socket ran `list` seven times across 90 seconds with no idle
| timeout and no drop, costing exactly 2 log lines instead of 14.
|
| These tests pin the connection ACCOUNTING (how many times the transport
| is connected/closed), because that count — not the command results — is
| the thing the operator's log is measuring.
|
*/

it('opens one connection for the first command and reuses it for later commands', function () {
    $transport = FakeRconTransport::respondingWith(
        rconAuthOkBytes().rconCommandReplyBytes('first').rconCommandReplyBytes('second').rconCommandReplyBytes('third'),
    );

    $client = new MinecraftRconClient($transport, persistent: true);

    $client->execute(RconCommand::from('list'));
    $client->execute(RconCommand::from('list'));
    $client->execute(RconCommand::from('list'));

    expect($transport->connectCalls)->toBe(1)
        ->and($transport->closeCalls)->toBe(0);
});

it('authenticates once for the whole persistent connection, not once per command', function () {
    $transport = FakeRconTransport::respondingWith(
        rconAuthOkBytes().rconCommandReplyBytes('first').rconCommandReplyBytes('second'),
    );

    $client = new MinecraftRconClient($transport, persistent: true, password: 'hunter2');

    $client->execute(RconCommand::from('list'));
    $client->execute(RconCommand::from('list'));

    $authPackets = array_filter(
        $transport->written,
        static fn (string $bytes): bool => unpack('V', substr($bytes, 8, 4))[1] === 3,
    );

    expect($authPackets)->toHaveCount(1);
});

it('still returns each command its own response body when reusing a connection', function () {
    $transport = FakeRconTransport::respondingWith(
        rconAuthOkBytes().rconCommandReplyBytes('There are 1 of a max of 20 players online: Alice')
            .rconCommandReplyBytes('There are 2 of a max of 20 players online: Alice, Bob'),
    );

    $client = new MinecraftRconClient($transport, persistent: true);

    expect($client->execute(RconCommand::from('list'))->body)
        ->toBe('There are 1 of a max of 20 players online: Alice')
        ->and($client->execute(RconCommand::from('list'))->body)
        ->toBe('There are 2 of a max of 20 players online: Alice, Bob');
});

it('opens and closes a fresh connection for every command when not persistent', function () {
    // The default stays connection-per-command: user-issued, audited
    // commands (App\Operations\Handlers\RconCommandHandler,
    // ServerStopHandler) are rare and must not hold a socket open.
    $transport = FakeRconTransport::respondingAcrossConnectionsWith(
        rconAuthOkBytes().rconCommandReplyBytes('first'),
        rconAuthOkBytes().rconCommandReplyBytes('second'),
    );

    $client = new MinecraftRconClient($transport);

    $client->execute(RconCommand::from('list'));
    $client->execute(RconCommand::from('list'));

    expect($transport->connectCalls)->toBe(2)
        ->and($transport->closeCalls)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Recovering a connection that went away while idle
|--------------------------------------------------------------------------
|
| A held socket can die between commands without the client noticing —
| most importantly when the Minecraft server restarts, which CraftKeeper
| itself can trigger (App\Operations\Handlers\ServerStopHandler). The
| write may even succeed into a local buffer; the failure only surfaces on
| the read. A poll that reported "RCON unreachable" every time the server
| bounced would be worse than the log noise it replaced, so a reused
| connection that fails gets ONE reconnect-and-retry before the failure is
| believed.
|
*/

it('reconnects and retries once when a reused connection has gone away', function () {
    $transport = FakeRconTransport::respondingAcrossConnectionsWith(
        // First connection: auth, one good reply, then the peer vanishes.
        rconAuthOkBytes().rconCommandReplyBytes('before restart'),
        // Second connection, after the client notices and reconnects.
        rconAuthOkBytes().rconCommandReplyBytes('after restart'),
    );

    $client = new MinecraftRconClient($transport, persistent: true);

    expect($client->execute(RconCommand::from('list'))->body)->toBe('before restart');

    // The socket is now dead, but the client does not know that yet.
    expect($client->execute(RconCommand::from('list'))->body)->toBe('after restart')
        ->and($transport->connectCalls)->toBe(2);
});

it('does not retry a command that failed on a connection it just opened', function () {
    // Retrying here would double every command against a server that is
    // simply down — one attempt per execute() is the contract.
    $transport = FakeRconTransport::respondingWith(rconAuthOkBytes());

    $client = new MinecraftRconClient($transport, persistent: true);

    expect(fn () => $client->execute(RconCommand::from('list')))
        ->toThrow(RconConnectionClosed::class);

    expect($transport->connectCalls)->toBe(1);
});

it('closes the dead socket before reconnecting so no descriptor is leaked', function () {
    $transport = FakeRconTransport::respondingAcrossConnectionsWith(
        rconAuthOkBytes().rconCommandReplyBytes('ok'),
        rconAuthOkBytes().rconCommandReplyBytes('ok again'),
    );

    $client = new MinecraftRconClient($transport, persistent: true);

    $client->execute(RconCommand::from('list'));
    $client->execute(RconCommand::from('list'));

    expect($transport->closeCalls)->toBe(1);
});

it('gives up and closes the connection when the retry also fails', function () {
    $transport = FakeRconTransport::respondingAcrossConnectionsWith(
        rconAuthOkBytes().rconCommandReplyBytes('ok'),
        // The reconnect gets through, but the server rejects the login.
        FakeRconTransport::packet(-1, 0, ''),
    );

    $client = new MinecraftRconClient($transport, persistent: true);
    $client->execute(RconCommand::from('list'));

    expect(fn () => $client->execute(RconCommand::from('list')))
        ->toThrow(RconAuthFailed::class);

    // Closed once for the dead socket, once for the failed retry — a
    // failed execute() never leaves a connection behind.
    expect($transport->closeCalls)->toBe(2);
});

it('reconnects on the next command after a failure rather than staying broken', function () {
    $transport = FakeRconTransport::respondingAcrossConnectionsWith(
        // Connection 1: authenticates, then dies before answering. Since
        // it was freshly opened, this failure is believed, not retried.
        rconAuthOkBytes(),
        // Connection 2: the NEXT execute() finds the server healthy again
        // — a failure must not latch the client into a broken state.
        rconAuthOkBytes().rconCommandReplyBytes('recovered'),
    );

    $client = new MinecraftRconClient($transport, persistent: true);

    expect(fn () => $client->execute(RconCommand::from('list')))->toThrow(RconConnectionClosed::class);

    expect($client->execute(RconCommand::from('list'))->body)->toBe('recovered');
});

it('closes a held connection when the client is disconnected', function () {
    $transport = FakeRconTransport::respondingWith(rconAuthOkBytes().rconCommandReplyBytes('ok'));

    $client = new MinecraftRconClient($transport, persistent: true);
    $client->execute(RconCommand::from('list'));

    expect($transport->closeCalls)->toBe(0);

    $client->disconnect();

    expect($transport->closeCalls)->toBe(1);
});

it('is safe to disconnect a client that never connected', function () {
    $transport = FakeRconTransport::respondingWith('');

    (new MinecraftRconClient($transport, persistent: true))->disconnect();

    expect($transport->closeCalls)->toBe(0);
});

it('closes only once when disconnected twice', function () {
    // WatchServerState disconnects in a `finally`, and a trapped SIGTERM
    // can arrive while that is already unwinding — a double disconnect
    // must not double-close a descriptor it no longer owns.
    $transport = FakeRconTransport::respondingWith(rconAuthOkBytes().rconCommandReplyBytes('ok'));

    $client = new MinecraftRconClient($transport, persistent: true);
    $client->execute(RconCommand::from('list'));

    $client->disconnect();
    $client->disconnect();

    expect($transport->closeCalls)->toBe(1);
});

it('opens a new connection when used again after being disconnected', function () {
    $transport = FakeRconTransport::respondingAcrossConnectionsWith(
        rconAuthOkBytes().rconCommandReplyBytes('before'),
        rconAuthOkBytes().rconCommandReplyBytes('after'),
    );

    $client = new MinecraftRconClient($transport, persistent: true);
    $client->execute(RconCommand::from('list'));
    $client->disconnect();

    // The client must know it is no longer connected — reusing the closed
    // transport here would talk into a dead socket.
    expect($client->execute(RconCommand::from('list'))->body)->toBe('after')
        ->and($transport->connectCalls)->toBe(2);
});
