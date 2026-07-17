<?php

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Config\ConfigRevisionService;
use App\Models\ConfigRevision;
use App\Models\User;
use App\Operations\OperationAuthor;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function apply_and_get_revision(User $admin, string $path, array $changes): ConfigRevision
{
    $current = file_get_contents(config('craftkeeper.minecraft_root').'/'.$path);
    $request = new ConfigChangeRequest($path, hash('sha256', $current), $changes);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));
    app(OperationService::class)->approve($operation->id, $admin);
    app(OperationService::class)->execute($operation->id);

    return ConfigRevision::query()->where('operation_id', $operation->id)->sole();
}

/*
|--------------------------------------------------------------------------
| Restore is a fresh reviewable proposal, never a blind file copy
|--------------------------------------------------------------------------
*/

it('restore proposes a NEW config.restore operation in Proposed status rather than writing directly', function () {
    $admin = User::factory()->create();
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v1\nallow-flight=false\n");

    $revision = apply_and_get_revision($admin, 'server.properties', [ConfigChange::replace('motd', 'v2')]);

    // The file has moved on since the revision was captured.
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v3\nallow-flight=false\n");

    $operation = app(ConfigRevisionService::class)->restore($revision, $admin);

    expect($operation->type)->toBe(OperationType::ConfigRestore)
        ->and($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->approved_at)->toBeNull();

    // Never touched the file directly — restore() only PROPOSES.
    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=v3\nallow-flight=false\n");
});

it('computes the changes needed to move the CURRENT content toward the revision, not the state at propose time', function () {
    $admin = User::factory()->create();
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v1\nallow-flight=false\n");

    $revision = apply_and_get_revision($admin, 'server.properties', [ConfigChange::replace('motd', 'v2')]);
    // Revision now captures "motd=v2\nallow-flight=false\n".

    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v3\nallow-flight=true\n");

    $operation = app(ConfigRevisionService::class)->restore($revision, $admin);

    expect($operation->redacted_input['changed_fields'])->toEqualCanonicalizing(['motd', 'allow-flight']);
});

it('restoring requires approval and execution before the file changes, and then matches the revision content', function () {
    $admin = User::factory()->create();
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v1\n");

    $revision = apply_and_get_revision($admin, 'server.properties', [ConfigChange::replace('motd', 'v2')]);

    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v3\n");

    $operation = app(ConfigRevisionService::class)->restore($revision, $admin);

    // Still untouched until approved + executed.
    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=v3\n");

    app(OperationService::class)->approve($operation->id, $admin);
    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Succeeded)
        ->and(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=v2\n");

    // Restoring itself created ANOTHER new revision (forward-only history).
    expect(ConfigRevision::query()->where('operation_id', $operation->id)->count())->toBe(1);
});

it('is subject to the same optimistic-concurrency conflict check as a normal edit', function () {
    $admin = User::factory()->create();
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v1\n");

    $revision = apply_and_get_revision($admin, 'server.properties', [ConfigChange::replace('motd', 'v2')]);

    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v3\n");
    $operation = app(ConfigRevisionService::class)->restore($revision, $admin);
    app(OperationService::class)->approve($operation->id, $admin);

    // Someone else changes the file again after the restore was approved.
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v4-external\n");

    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Failed)
        ->and($result->error_code)->toBe('config.hash_mismatch')
        ->and(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=v4-external\n");
});

it('restoring a secret field keeps the real value out of the restore proposal display while still reaching the file on approval', function () {
    $admin = User::factory()->create();
    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=first-secret\n");

    $revision = apply_and_get_revision($admin, 'server.properties', [ConfigChange::replace('rcon.password', 'second-secret')]);

    file_put_contents($this->minecraftRoot.'/server.properties', "rcon.password=third-secret\n");

    $operation = app(ConfigRevisionService::class)->restore($revision, $admin);

    expect(json_encode($operation->redacted_input))
        ->not->toContain('first-secret')
        ->not->toContain('second-secret')
        ->not->toContain('third-secret');

    app(OperationService::class)->approve($operation->id, $admin);
    app(OperationService::class)->execute($operation->id);

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("rcon.password=second-secret\n");
});
