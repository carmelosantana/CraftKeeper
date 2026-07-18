<?php

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Models\ConfigChangePayload;
use App\Models\Operation;
use App\Models\User;
use App\Operations\OperationAuthor;
use App\Operations\OperationRisk;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Tests\Support\TempMinecraftRoot;

/**
 * Data-minimization: App\Models\ConfigChangePayload holds the RAW proposed
 * change set (which may include a secret like a new rcon.password),
 * encrypted at rest but never deleted historically. It's only needed while
 * an operation is IN-FLIGHT (Proposed/Approved) — once the operation
 * reaches a terminal outcome, the payload is dead weight: rollback restores
 * from filesystem snapshots (see Concerns\AppliesConfigChanges::rollback()),
 * never from this table. These tests pin down that the payload is deleted
 * once it can no longer be used, and NOT before.
 */
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

it('deletes the ConfigChangePayload row once a config.apply operation succeeds, but only after the real value reached the file', function () {
    $admin = User::factory()->create();
    $contents = "rcon.password=old-secret\nmotd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));
    app(OperationService::class)->approve($operation->id, $admin);

    // Sanity: the payload exists right up until execute() runs.
    expect(ConfigChangePayload::query()->where('operation_id', $operation->id)->exists())->toBeTrue();

    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Succeeded);

    // The real secret actually reached the file BEFORE the payload was
    // wiped — proving deletion happens after apply, never before.
    expect(file_get_contents($this->minecraftRoot.'/server.properties'))
        ->toBe("rcon.password=brand-new-secret\nmotd=hi\n");

    // The encrypted row holding that same raw value is now gone.
    expect(ConfigChangePayload::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});

it('deletes the ConfigChangePayload row once a config.apply operation fails execution (e.g. an expired proposal)', function () {
    $contents = "allow-flight=false\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $operation = Operation::factory()
        ->status(OperationStatus::Approved)
        ->ofType(OperationType::ConfigApply)
        ->create([
            'target' => 'server.properties',
            'risk' => OperationRisk::Standard,
            'redacted_input' => [
                'base_sha256' => hash('sha256', $contents),
                'changed_fields' => ['allow-flight'],
                'diff' => '',
                'valid' => true,
                'diagnostics' => [],
                'restart_impact' => 'restart',
                'documentation' => [],
                'expires_at' => now()->subDay()->toIso8601String(),
            ],
        ]);

    ConfigChangePayload::query()->create([
        'operation_id' => $operation->id,
        'changes' => [['kind' => 'replace', 'path' => 'allow-flight', 'value' => true]],
    ]);

    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Failed)
        ->and($result->error_code)->toBe('config.proposal_expired');

    expect(ConfigChangePayload::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});

it('retains the ConfigChangePayload row for a still-Proposed config operation', function () {
    $contents = "rcon.password=old-secret\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user(1));

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and(ConfigChangePayload::query()->where('operation_id', $operation->id)->exists())->toBeTrue();
});

it('retains the ConfigChangePayload row for a still-Approved (not yet executed) config operation', function () {
    $admin = User::factory()->create();
    $contents = "rcon.password=old-secret\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));
    app(OperationService::class)->approve($operation->id, $admin);

    expect(ConfigChangePayload::query()->where('operation_id', $operation->id)->exists())->toBeTrue();
});

it('deletes the ConfigChangePayload row once a config proposal is rejected', function () {
    $admin = User::factory()->create();
    $contents = "rcon.password=old-secret\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));

    expect(ConfigChangePayload::query()->where('operation_id', $operation->id)->exists())->toBeTrue();

    $rejected = app(OperationService::class)->reject($operation->id, $admin, 'not needed right now');

    expect($rejected->status)->toBe(OperationStatus::Rejected)
        ->and(ConfigChangePayload::query()->where('operation_id', $operation->id)->exists())->toBeFalse();
});
