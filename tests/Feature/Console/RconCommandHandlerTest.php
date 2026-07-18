<?php

use App\Console\CommandPolicy;
use App\Console\MinecraftRconClient;
use App\Models\AuditEvent;
use App\Models\Operation;
use App\Models\RconCommandPayload;
use App\Operations\Exceptions\IllegalOperationTransition;
use App\Operations\Handlers\RconCommandHandler;
use App\Operations\OperationActorType;
use App\Operations\OperationHandlerRegistry;
use App\Operations\OperationRisk;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Tests\fixtures\rcon\FakeRconTransport;

function successfulResponseBytes(string $body): string
{
    return FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 0, $body)
        .FakeRconTransport::packet(3, 0, '');
}

/*
|--------------------------------------------------------------------------
| Success paths
|--------------------------------------------------------------------------
*/

it('executes an approved, non-secret elevated command and records a system audit event', function () {
    $fakeTransport = FakeRconTransport::respondingWith(successfulResponseBytes('Set the time to 1000'));
    $handler = new RconCommandHandler(new MinecraftRconClient($fakeTransport), new CommandPolicy);

    $operation = Operation::factory()
        ->status(OperationStatus::Approved)
        ->ofType(OperationType::RconCommand)
        ->state(['target' => 'time set day', 'redacted_input' => ['command' => 'time set day'], 'risk' => OperationRisk::Elevated])
        ->create();

    $result = $handler->execute($operation);

    expect($result->successful)->toBeTrue()
        ->and($result->message)->toBe('Executed the "time" command.')
        ->and($result->message)->not->toContain('time set day')
        ->and($result->output['response'])->toBe('Set the time to 1000');

    $event = AuditEvent::query()->where('operation_id', $operation->id)->where('event_type', 'rcon.command_executed')->sole();
    expect($event->actor_type)->toBe(OperationActorType::System)
        ->and($event->payload['category'])->toBe('time');
});

it('executes an approved secret-shaped command from its stashed payload, then deletes the payload', function () {
    $fakeTransport = FakeRconTransport::respondingWith(successfulResponseBytes('Password changed.'));
    $handler = new RconCommandHandler(new MinecraftRconClient($fakeTransport), new CommandPolicy);

    $operation = Operation::factory()
        ->status(OperationStatus::Approved)
        ->ofType(OperationType::RconCommand)
        ->state(['target' => 'login ••••••', 'redacted_input' => ['command' => 'login ••••••'], 'risk' => OperationRisk::Elevated])
        ->create();

    RconCommandPayload::query()->create([
        'operation_id' => $operation->id,
        'command' => 'login mySuperSecretPass123',
    ]);

    $result = $handler->execute($operation);

    expect($result->successful)->toBeTrue()
        ->and($result->message)->not->toContain('mySuperSecretPass123')
        ->and($result->output['response'])->toBe('Password changed.');

    // The auth packet aside, the exec packet actually sent must have
    // carried the REAL secret text — proving the handler read it from
    // the payload, not from redacted_input.
    $sentBodies = array_map(
        fn (string $raw) => substr($raw, 12, strlen($raw) - 12 - 2),
        $fakeTransport->written,
    );
    expect($sentBodies)->toContain('login mySuperSecretPass123');

    expect(RconCommandPayload::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Failure paths — distinct typed error codes, no secrets in the message
|--------------------------------------------------------------------------
*/

it('maps a failed authentication to a typed error code without leaking the password', function () {
    $fakeTransport = FakeRconTransport::respondingWith(FakeRconTransport::packet(-1, 0, ''));
    $handler = new RconCommandHandler(new MinecraftRconClient($fakeTransport, password: 'the-real-password'), new CommandPolicy);

    $operation = Operation::factory()
        ->status(OperationStatus::Approved)
        ->ofType(OperationType::RconCommand)
        ->state(['target' => 'stop', 'redacted_input' => ['command' => 'stop'], 'risk' => OperationRisk::Elevated])
        ->create();

    $result = $handler->execute($operation);

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('rcon.auth_failed')
        ->and($result->message)->not->toContain('the-real-password');
});

it('fails with a typed error code when no command payload or metadata can be found', function () {
    $fakeTransport = FakeRconTransport::respondingWith('');
    $handler = new RconCommandHandler(new MinecraftRconClient($fakeTransport), new CommandPolicy);

    $operation = Operation::factory()
        ->status(OperationStatus::Approved)
        ->ofType(OperationType::RconCommand)
        ->state(['target' => null, 'redacted_input' => [], 'risk' => OperationRisk::Elevated])
        ->create();

    $result = $handler->execute($operation);

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('rcon.payload_missing');
    // No transport bytes were written at all — the handler never even
    // attempted to talk to RCON for a command it couldn't resolve.
    expect($fakeTransport->written)->toBe([]);
});

it('rollback() always reports an rcon command cannot be rolled back', function () {
    $handler = new RconCommandHandler(new MinecraftRconClient(FakeRconTransport::respondingWith('')), new CommandPolicy);
    $operation = Operation::factory()->ofType(OperationType::RconCommand)->create();

    $result = $handler->rollback($operation);

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('rcon.command_not_rollbackable');
});

/*
|--------------------------------------------------------------------------
| Structural guarantee: never invoked for an unapproved operation
|--------------------------------------------------------------------------
*/

it('never sends a byte over RCON for a proposed-but-not-approved operation', function () {
    $fakeTransport = FakeRconTransport::respondingWith('');
    $handler = new RconCommandHandler(new MinecraftRconClient($fakeTransport), new CommandPolicy);

    // A private registry/service, scoped to this test, so this is
    // provably the only handler that could ever run — see
    // RconCommandServiceTest's "runs a safe command end-to-end" test for
    // why the shared container registry is deliberately avoided here.
    $operations = new OperationService(new OperationHandlerRegistry([$handler]));

    $operation = Operation::factory()
        ->status(OperationStatus::Proposed)
        ->ofType(OperationType::RconCommand)
        ->state(['target' => 'stop', 'redacted_input' => ['command' => 'stop']])
        ->create();

    expect(fn () => $operations->execute($operation->id))->toThrow(IllegalOperationTransition::class);

    expect($fakeTransport->written)->toBe([]);
});
