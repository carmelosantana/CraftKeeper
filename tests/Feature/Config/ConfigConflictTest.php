<?php

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Config\Exceptions\ConfigConflict;
use App\Models\Operation;
use App\Models\User;
use App\Operations\OperationAuthor;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);

    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\nallow-flight=false\n");
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

/*
|--------------------------------------------------------------------------
| The brief's verbatim stale-edit test
|--------------------------------------------------------------------------
*/

it('returns a conflict instead of overwriting a file changed outside CraftKeeper', function () {
    $request = new ConfigChangeRequest('server.properties', 'old-sha', [
        ConfigChange::replace('allow-flight', true),
    ]);

    expect(fn () => app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1)))
        ->toThrow(ConfigConflict::class);
});

it('never creates an Operation when the base hash is stale', function () {
    $request = new ConfigChangeRequest('server.properties', 'old-sha', [
        ConfigChange::replace('allow-flight', true),
    ]);

    try {
        app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));
    } catch (ConfigConflict) {
        // expected
    }

    expect(Operation::query()->count())->toBe(0);
});

it('reports the expected and actual sha256 on the conflict', function () {
    $realHash = hash('sha256', "motd=hi\nallow-flight=false\n");

    $request = new ConfigChangeRequest('server.properties', 'old-sha', [
        ConfigChange::replace('allow-flight', true),
    ]);

    try {
        app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));
        $this->fail('Expected ConfigConflict to be thrown.');
    } catch (ConfigConflict $e) {
        expect($e->expectedSha256)->toBe('old-sha')
            ->and($e->actualSha256)->toBe($realHash);
    }
});

/*
|--------------------------------------------------------------------------
| TOCTOU: the file changes AFTER a valid propose() but BEFORE execute()
|--------------------------------------------------------------------------
*/

it('fails as a conflict at execute time when the file changed after propose but before approval/execution, leaving the original intact', function () {
    $admin = User::factory()->create();
    $realHash = hash('sha256', "motd=hi\nallow-flight=false\n");

    $request = new ConfigChangeRequest('server.properties', $realHash, [
        ConfigChange::replace('allow-flight', true),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));

    app(OperationService::class)->approve($operation->id, $admin);

    // Simulate an external actor (the Minecraft server itself, an admin
    // editing the file directly, ...) changing the file after propose()
    // read it but before this operation executes.
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=changed-externally\nallow-flight=false\n");

    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Failed)
        ->and($result->error_code)->toBe('config.hash_mismatch');

    // The externally-written content must be completely untouched — no
    // blind overwrite occurred.
    expect(file_get_contents($this->minecraftRoot.'/server.properties'))
        ->toBe("motd=changed-externally\nallow-flight=false\n");
});
