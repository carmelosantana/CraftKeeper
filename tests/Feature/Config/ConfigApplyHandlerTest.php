<?php

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Config\ConfigDiscoveryService;
use App\Config\ConfigFormatRegistry;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Events\OperationUpdated;
use App\Filesystem\AtomicFileWriter;
use App\Filesystem\LocalMinecraftFilesystem;
use App\Filesystem\SnapshotStore;
use App\Models\AuditEvent;
use App\Models\ConfigChangePayload;
use App\Models\ConfigRevision;
use App\Models\Operation;
use App\Models\User;
use App\Operations\Handlers\ConfigApplyHandler;
use App\Operations\OperationAuthor;
use App\Operations\OperationRisk;
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

/*
|--------------------------------------------------------------------------
| Full pipeline, real container wiring: propose -> approve -> execute
|--------------------------------------------------------------------------
*/

it('applies the real secret value to the file while every stored trace stays redacted, and creates exactly one revision and one dedicated audit event', function () {
    $admin = User::factory()->create();
    $contents = "rcon.password=old-secret\nmotd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));
    app(OperationService::class)->approve($operation->id, $admin);

    $result = app(OperationService::class)->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Succeeded);

    // The REAL value reached the actual file on disk.
    expect(file_get_contents($this->minecraftRoot.'/server.properties'))
        ->toBe("rcon.password=brand-new-secret\nmotd=hi\n");

    // Exactly one revision, exactly one dedicated audit event for this apply.
    expect(ConfigRevision::query()->where('operation_id', $operation->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('operation_id', $operation->id)->where('event_type', 'config.applied')->count())->toBe(1);

    // Nothing secret anywhere else: operation outcome/message, every
    // audit event's payload for this operation, the broadcast payload.
    expect($result->outcome)->not->toContain('brand-new-secret')->not->toContain('old-secret');

    foreach (AuditEvent::query()->where('operation_id', $operation->id)->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain('brand-new-secret')->not->toContain('old-secret');
    }

    $broadcast = OperationUpdated::fromOperation($operation->fresh())->broadcastWith();
    $encodedBroadcast = json_encode($broadcast);
    expect($encodedBroadcast)->not->toContain('brand-new-secret')->not->toContain('old-secret');

    // The revision's own redacted_diff is likewise safe, but points at a
    // real (correctly restorable) snapshot on disk.
    $revision = ConfigRevision::query()->where('operation_id', $operation->id)->sole();
    expect($revision->redacted_diff)->not->toContain('brand-new-secret')->not->toContain('old-secret')
        ->and(file_get_contents($revision->snapshot_path))->toBe("rcon.password=brand-new-secret\nmotd=hi\n");
});

it('creates the ConfigFile registry row for a newly-touched path', function () {
    $admin = User::factory()->create();
    $contents = "allow-flight=false\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('allow-flight', true),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));
    app(OperationService::class)->approve($operation->id, $admin);
    app(OperationService::class)->execute($operation->id);

    $revision = ConfigRevision::query()->where('operation_id', $operation->id)->sole();

    expect($revision->configFile->path)->toBe('server.properties');
});

it('fails cleanly without writing when the proposal has expired', function () {
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
        ->and($result->error_code)->toBe('config.proposal_expired')
        ->and(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe($contents);
});

/*
|--------------------------------------------------------------------------
| Post-write verification failure -> compensating rollback from snapshot
|--------------------------------------------------------------------------
*/

/**
 * An AtomicFileWriter whose renameFile() performs the REAL rename (so
 * everything up to that point — temp file creation, real fwrite(), real
 * fsync() — happens for real) but then, on selected calls, corrupts the
 * just-renamed file's bytes, so AtomicFileWriter's own post-rename
 * verification (re-read + compare hash) fails and it throws
 * AtomicWriteFailed::verificationMismatch(), simulating an extremely rare
 * concurrent external mutation in the instant between rename() and the
 * verification re-read.
 */
function corrupting_atomic_writer(array $corruptOnCalls): AtomicFileWriter
{
    return new class($corruptOnCalls) extends AtomicFileWriter
    {
        public int $calls = 0;

        public function __construct(private readonly array $corruptOnCalls) {}

        protected function renameFile(string $from, string $to): bool
        {
            $this->calls++;
            $result = rename($from, $to);

            if ($result && in_array($this->calls, $this->corruptOnCalls, true)) {
                file_put_contents($to, 'CORRUPTED-BY-TEST', FILE_APPEND);
            }

            return $result;
        }
    };
}

function config_apply_handler_with(AtomicFileWriter $writer): ConfigApplyHandler
{
    $filesystem = new LocalMinecraftFilesystem(new ConfigDiscoveryService, $writer, new SnapshotStore);

    return new ConfigApplyHandler($filesystem, app(ConfigFormatRegistry::class), app(ConfigSchemaRegistry::class));
}

it('rolls back to the pre-write snapshot and reports the failure when post-write verification fails', function () {
    $admin = User::factory()->create();
    $contents = "allow-flight=false\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('allow-flight', true),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));
    app(OperationService::class)->approve($operation->id, $admin);

    // Corrupt only the FIRST rename (the primary write) — the rollback's
    // own write (the second writeAtomically call) should then succeed
    // cleanly and restore the original bytes.
    $handler = config_apply_handler_with(corrupting_atomic_writer([1]));

    $result = $handler->execute($operation->fresh());

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('config.write_failed_rolled_back')
        ->and($result->output['primary_error'] ?? null)->not->toBeNull();

    // The file is back to its ORIGINAL content — never left corrupted,
    // never left with the half-applied new value.
    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe($contents);

    // No revision was recorded for a write that ultimately failed.
    expect(ConfigRevision::query()->where('operation_id', $operation->id)->count())->toBe(0);
});

it('records both failures without throwing when the compensating rollback also fails', function () {
    $admin = User::factory()->create();
    $contents = "allow-flight=false\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('allow-flight', true),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));
    app(OperationService::class)->approve($operation->id, $admin);

    // Corrupt BOTH the primary write's rename AND the rollback attempt's
    // rename — the compensating rollback itself now also fails
    // verification, so both failures must be captured together.
    $handler = config_apply_handler_with(corrupting_atomic_writer([1, 2]));

    $result = $handler->execute($operation->fresh());

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('config.write_failed_rollback_failed')
        ->and($result->output['primary_error'] ?? null)->not->toBeNull()
        ->and($result->output['rollback_error'] ?? null)->not->toBeNull();

    expect(ConfigRevision::query()->where('operation_id', $operation->id)->count())->toBe(0);
});

it('never lets a post-write verification failure or its rollback outcome mention a secret value', function () {
    $admin = User::factory()->create();
    $contents = "rcon.password=old-secret\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $request = new ConfigChangeRequest('server.properties', hash('sha256', $contents), [
        ConfigChange::replace('rcon.password', 'brand-new-secret'),
    ]);

    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::user($admin->id));
    app(OperationService::class)->approve($operation->id, $admin);

    $handler = config_apply_handler_with(corrupting_atomic_writer([1, 2]));
    $result = $handler->execute($operation->fresh());

    $encoded = json_encode(['message' => $result->message, 'output' => $result->output]);
    expect($encoded)->not->toContain('brand-new-secret')->not->toContain('old-secret');
});
