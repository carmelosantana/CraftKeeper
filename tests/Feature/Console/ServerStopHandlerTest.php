<?php

use App\Console\MinecraftRconClient;
use App\Models\Operation;
use App\Operations\Handlers\ServerStopHandler;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Tests\fixtures\rcon\FakeRconTransport;

/**
 * Decode the body of every raw packet MinecraftRconClient wrote to a
 * FakeRconTransport, in order.
 *
 * @return list<string>
 */
function writtenBodies(FakeRconTransport $transport): array
{
    return array_map(
        fn (string $raw) => substr($raw, 12, strlen($raw) - 12 - 2),
        $transport->written,
    );
}

it('sends save-all flush strictly before stop, over RCON, never Docker', function () {
    // Two full connect->auth->exec->terminator->close cycles' worth of
    // responses, back to back — one per RconClient::execute() call.
    $bytes = FakeRconTransport::packet(1, 0, '').FakeRconTransport::packet(3, 0, '')
        .FakeRconTransport::packet(1, 0, '').FakeRconTransport::packet(3, 0, '');
    $fakeTransport = FakeRconTransport::respondingWith($bytes);

    $handler = new ServerStopHandler(new MinecraftRconClient($fakeTransport));
    $operation = Operation::factory()->status(OperationStatus::Approved)->ofType(OperationType::ServerStop)->create();

    $result = $handler->execute($operation);

    expect($result->successful)->toBeTrue()
        ->and($result->message)->toBe('Waiting for the Minecraft container restart policy.');

    $bodies = writtenBodies($fakeTransport);
    // written = [auth1, exec1, terminator1, auth2, exec2, terminator2]
    $flushIndex = array_search('save-all flush', $bodies, true);
    $stopIndex = array_search('stop', $bodies, true);

    expect($flushIndex)->not->toBeFalse()
        ->and($stopIndex)->not->toBeFalse()
        ->and($flushIndex)->toBeLessThan($stopIndex);
});

it('fails with a typed error code and sends nothing further when the RCON auth fails', function () {
    $fakeTransport = FakeRconTransport::respondingWith(FakeRconTransport::packet(-1, 0, ''));
    $handler = new ServerStopHandler(new MinecraftRconClient($fakeTransport));
    $operation = Operation::factory()->status(OperationStatus::Approved)->ofType(OperationType::ServerStop)->create();

    $result = $handler->execute($operation);

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('rcon.auth_failed');

    // Only the auth packet for the (failed) "save-all flush" attempt was
    // ever sent — "stop" must never be attempted once the sequence has
    // already failed.
    $bodies = writtenBodies($fakeTransport);
    expect($bodies)->not->toContain('stop');
});

it('rollback() always reports a server stop cannot be rolled back', function () {
    $handler = new ServerStopHandler(new MinecraftRconClient(FakeRconTransport::respondingWith('')));
    $operation = Operation::factory()->ofType(OperationType::ServerStop)->create();

    $result = $handler->rollback($operation);

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('server.stop_not_rollbackable');
});

it('never shells out to Docker or any container API — verified against its own compiled code, not just comments', function () {
    $source = file_get_contents(app_path('Operations/Handlers/ServerStopHandler.php'));
    expect($source)->not->toBeFalse();

    // Strip comments/docblocks first — this class's own documentation
    // legitimately says "never calls docker" in prose; what must be
    // absent is the word appearing in actual CODE (a shell command, a
    // socket path, a Process:: call), not in an explanatory comment.
    $codeOnly = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codeOnly .= is_array($token) ? $token[1] : $token;
    }

    expect(strtolower($codeOnly))->not->toContain('docker')
        ->and($codeOnly)->not->toContain('shell_exec')
        ->and($codeOnly)->not->toContain('proc_open')
        ->and($codeOnly)->not->toContain('Process::')
        ->and($codeOnly)->not->toContain('exec(');
});
