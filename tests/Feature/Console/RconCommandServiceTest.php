<?php

use App\Console\CommandPolicy;
use App\Console\Exceptions\CommandNotSafe;
use App\Console\MinecraftRconClient;
use App\Console\RconCommandService;
use App\Models\Operation;
use App\Models\RconCommandPayload;
use App\Models\User;
use App\Operations\Handlers\RconCommandHandler;
use App\Operations\OperationAuthor;
use App\Operations\OperationHandlerRegistry;
use App\Operations\OperationRisk;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use Tests\fixtures\rcon\FakeRconTransport;

function rconCommandService(): RconCommandService
{
    return app(RconCommandService::class);
}

/*
|--------------------------------------------------------------------------
| proposeCommand(): elevated / non-secret commands persist raw
|--------------------------------------------------------------------------
*/

it('proposes an elevated, non-secret command with its real text stored on the operation', function () {
    $operation = rconCommandService()->proposeCommand('op Steve', OperationAuthor::mcp('client-1'));

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->risk)->toBe(OperationRisk::Elevated)
        ->and($operation->target)->toBe('op Steve')
        ->and($operation->redacted_input['command'])->toBe('op Steve')
        ->and(RconCommandPayload::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});

it('proposes a safe command as Standard risk', function () {
    $operation = rconCommandService()->proposeCommand('list', OperationAuthor::mcp('client-1'));

    expect($operation->risk)->toBe(OperationRisk::Standard)
        ->and($operation->target)->toBe('list');
});

/*
|--------------------------------------------------------------------------
| proposeCommand(): secret-shaped commands are never persisted raw
|--------------------------------------------------------------------------
*/

it('never persists the raw text of a secret-shaped command on the operation, and stashes it encrypted instead', function () {
    $operation = rconCommandService()->proposeCommand('login mySuperSecretPass123', OperationAuthor::mcp('client-1'));

    expect($operation->target)->not->toContain('mySuperSecretPass123')
        ->and($operation->redacted_input['command'])->not->toContain('mySuperSecretPass123')
        ->and($operation->target)->toBe('login ••••••');

    $payload = RconCommandPayload::query()->where('operation_id', $operation->id)->sole();
    expect($payload->command)->toBe('login mySuperSecretPass123');

    // The raw command must never sit in the database in plaintext, even
    // in the dedicated payload table — only via the model's decrypting
    // cast.
    $rawColumn = DB::table('rcon_command_payloads')->where('operation_id', $operation->id)->value('command');
    expect($rawColumn)->not->toBe('mySuperSecretPass123')
        ->and($rawColumn)->not->toContain('mySuperSecretPass123');
});

it('deletes a secret command payload when its operation is rejected', function () {
    $admin = User::factory()->create();
    $operation = rconCommandService()->proposeCommand('login mySuperSecretPass123', OperationAuthor::user($admin->id));

    expect(RconCommandPayload::query()->where('operation_id', $operation->id)->exists())->toBeTrue();

    app(OperationService::class)->reject($operation->id, $admin, 'not needed');

    expect(RconCommandPayload::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| runSafeCommand(): the lighter path, safe-only
|--------------------------------------------------------------------------
*/

it('refuses to propose anything at all for a non-safe command via the lighter path', function () {
    $admin = User::factory()->create();

    expect(fn () => rconCommandService()->runSafeCommand('op Steve', $admin))
        ->toThrow(CommandNotSafe::class);

    expect(Operation::query()->count())->toBe(0);
});

it('runs a safe command end-to-end through propose+self-approve+execute in one call', function () {
    $admin = User::factory()->create();

    $bytes = FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 0, 'There are 0 of a max of 20 players online:')
        .FakeRconTransport::packet(3, 0, '');
    $fakeTransport = FakeRconTransport::respondingWith($bytes);

    // Deliberately NOT app(OperationHandlerRegistry::class): the
    // container's shared registry already has the REAL
    // RconCommandHandler bound (App\Providers\AppServiceProvider), wired
    // to a REAL StreamRconTransport — and OperationHandlerRegistry
    // resolves the FIRST matching handler, so registering a second one
    // for the same type on the shared singleton would never actually be
    // reached. Building a private registry/service here guarantees this
    // test's FakeRconTransport is the only thing execute() can ever talk
    // to — no socket is at risk of being opened.
    $registry = new OperationHandlerRegistry([
        new RconCommandHandler(new MinecraftRconClient($fakeTransport), new CommandPolicy),
    ]);
    $operations = new OperationService($registry);
    $service = new RconCommandService($operations, new CommandPolicy);

    $operation = $service->runSafeCommand('list', $admin);

    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->approved_by_id)->toBe((string) $admin->id);
});
