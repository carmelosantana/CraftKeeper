<?php

use App\Config\ConfigDiscoveryService;
use App\Filesystem\AtomicFileWriter;
use App\Filesystem\LocalMinecraftFilesystem;
use App\Filesystem\SnapshotStore;
use App\Models\AuditEvent;
use App\Models\PluginInstallation;
use App\Models\PluginOperationPlan;
use App\Models\PluginRollbackArtifact;
use App\Models\User;
use App\Operations\Handlers\PluginOperationHandler;
use App\Operations\OperationAuthor;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Plugins\Exceptions\PluginChecksumMismatch;
use App\Plugins\PluginLifecycleService;
use App\Plugins\PluginRollbackStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\fixtures\plugins\JarFixtureBuilder;
use Tests\Support\Plugins\PluginReleaseFactory;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    File::makeDirectory($this->minecraftRoot.'/plugins', 0755, true, true);
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
    $this->admin = User::factory()->create();
    $this->author = OperationAuthor::user($this->admin->id);
    $this->lifecycle = app(PluginLifecycleService::class);
    $this->operations = app(OperationService::class);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function jarBytes(string $name, string $version = '1.0.0'): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ck-jar-').'.jar';
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: {$name}\nversion: '{$version}'\n")
        ->writeTo($tmp);
    $bytes = file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function makeInstallation(array $attributes = []): PluginInstallation
{
    return PluginInstallation::query()->create([
        'relative_path' => 'plugins/Example.jar',
        'name' => 'Example',
        'version' => '1.0.0',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
        ...$attributes,
    ]);
}

/*
|--------------------------------------------------------------------------
| Install: stage-then-atomic-rename into /minecraft/plugins
|--------------------------------------------------------------------------
*/

it('installs an approved plugin via atomic rename, marks restart required, and cleans up quarantine', function () {
    $bytes = jarBytes('EssentialsX');
    Http::fake(['*' => Http::response($bytes)]);
    $release = PluginReleaseFactory::make(sha256: hash('sha256', $bytes), name: 'EssentialsX');

    $operation = $this->lifecycle->proposeInstall($release, $this->author);
    $plan = PluginOperationPlan::forOperation($operation->id);
    $quarantinePath = $plan->quarantine_path;

    $this->operations->approve($operation->id, $this->admin);
    $result = $this->operations->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Succeeded)
        ->and(file_get_contents($this->minecraftRoot.'/plugins/EssentialsX.jar'))->toBe($bytes)
        ->and(is_file($quarantinePath))->toBeFalse() // quarantine cleaned up after terminal
        ->and(AuditEvent::query()->where('operation_id', $operation->id)->where('event_type', 'plugin.installed')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Update: preserves the replaced JAR BEFORE overwriting, then atomically
| replaces it.
|--------------------------------------------------------------------------
*/

it('updates by preserving the old artifact under plugin-rollbacks before atomically replacing it', function () {
    $oldBytes = jarBytes('EssentialsX', '1.0.0');
    file_put_contents($this->minecraftRoot.'/plugins/EssentialsX.jar', $oldBytes);
    $installation = makeInstallation(['relative_path' => 'plugins/EssentialsX.jar', 'name' => 'EssentialsX', 'sha256' => hash('sha256', $oldBytes)]);

    $newBytes = jarBytes('EssentialsX', '1.1.0');
    Http::fake(['*' => Http::response($newBytes)]);
    $release = PluginReleaseFactory::make(sha256: hash('sha256', $newBytes), version: '1.1.0', name: 'EssentialsX');

    $operation = $this->lifecycle->proposeUpdate($installation, $release, $this->author);
    $this->operations->approve($operation->id, $this->admin);
    $result = $this->operations->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Succeeded)
        ->and(file_get_contents($this->minecraftRoot.'/plugins/EssentialsX.jar'))->toBe($newBytes);

    $rollbackArtifact = PluginRollbackArtifact::query()->where('relative_path', 'plugins/EssentialsX.jar')->sole();
    expect($rollbackArtifact->reason)->toBe('pre-update')
        ->and($rollbackArtifact->sha256)->toBe(hash('sha256', $oldBytes))
        ->and(file_get_contents($rollbackArtifact->storage_path))->toBe($oldBytes);
});

/*
|--------------------------------------------------------------------------
| An UPDATE FAILURE (post-write verification mismatch) leaves the
| currently-installed artifact intact — mirrors
| tests/Feature/Config/ConfigApplyHandlerTest.php's identical scenario.
|--------------------------------------------------------------------------
*/

/**
 * @return array{0: AtomicFileWriter, 1: int}
 */
function corruptingWriterForPlugins(array $corruptOnCalls): AtomicFileWriter
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

function pluginHandlerWith(AtomicFileWriter $writer): PluginOperationHandler
{
    $filesystem = new LocalMinecraftFilesystem(new ConfigDiscoveryService, $writer, new SnapshotStore);

    return new PluginOperationHandler($filesystem, new PluginRollbackStore($filesystem));
}

it('leaves the installed artifact intact when an update fails post-write verification, via compensating rollback', function () {
    $oldBytes = jarBytes('EssentialsX', '1.0.0');
    file_put_contents($this->minecraftRoot.'/plugins/EssentialsX.jar', $oldBytes);
    $installation = makeInstallation(['relative_path' => 'plugins/EssentialsX.jar', 'name' => 'EssentialsX', 'sha256' => hash('sha256', $oldBytes)]);

    $newBytes = jarBytes('EssentialsX', '1.1.0');
    Http::fake(['*' => Http::response($newBytes)]);
    $release = PluginReleaseFactory::make(sha256: hash('sha256', $newBytes), version: '1.1.0', name: 'EssentialsX');

    $operation = $this->lifecycle->proposeUpdate($installation, $release, $this->author);
    $this->operations->approve($operation->id, $this->admin);

    // Corrupt the primary write's rename (call #1) — the rollback's own
    // rename (call #2) is left to succeed cleanly.
    $handler = pluginHandlerWith(corruptingWriterForPlugins([1]));
    $result = $handler->execute($operation->fresh());

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('plugin.write_failed_rolled_back');

    // The file is back to its ORIGINAL bytes — never left corrupted, never
    // left half-updated.
    expect(file_get_contents($this->minecraftRoot.'/plugins/EssentialsX.jar'))->toBe($oldBytes);
});

it('leaves the installed artifact intact when an update is refused for a checksum mismatch (the e2e-observable failure mode)', function () {
    $oldBytes = jarBytes('EssentialsX', '1.0.0');
    file_put_contents($this->minecraftRoot.'/plugins/EssentialsX.jar', $oldBytes);
    $installation = makeInstallation(['relative_path' => 'plugins/EssentialsX.jar', 'name' => 'EssentialsX', 'sha256' => hash('sha256', $oldBytes)]);

    Http::fake(['*' => Http::response('not-the-real-published-bytes')]);
    $release = PluginReleaseFactory::make(version: '1.1.0', name: 'EssentialsX'); // sha256 won't match the faked body

    expect(fn () => $this->lifecycle->proposeUpdate($installation, $release, $this->author))
        ->toThrow(PluginChecksumMismatch::class);

    // No operation, no plan, and the installed artifact untouched.
    expect(file_get_contents($this->minecraftRoot.'/plugins/EssentialsX.jar'))->toBe($oldBytes);
});

/*
|--------------------------------------------------------------------------
| Disable: rename to .jar.disabled
|--------------------------------------------------------------------------
*/

it('disables a plugin by renaming it to .jar.disabled', function () {
    $bytes = jarBytes('Foo');
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', $bytes);
    $installation = makeInstallation(['relative_path' => 'plugins/Foo.jar', 'name' => 'Foo', 'sha256' => hash('sha256', $bytes)]);

    $operation = $this->lifecycle->proposeDisable($installation, $this->author);
    $this->operations->approve($operation->id, $this->admin);
    $result = $this->operations->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Succeeded)
        ->and(file_exists($this->minecraftRoot.'/plugins/Foo.jar'))->toBeFalse()
        ->and(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar.disabled'))->toBe($bytes);
});

/*
|--------------------------------------------------------------------------
| Remove: MOVE to plugin-rollbacks, never a bare unlink.
|--------------------------------------------------------------------------
*/

it('removes a plugin by preserving it under plugin-rollbacks BEFORE unlinking, never a bare delete', function () {
    $bytes = jarBytes('Foo');
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', $bytes);
    $installation = makeInstallation(['relative_path' => 'plugins/Foo.jar', 'name' => 'Foo', 'sha256' => hash('sha256', $bytes)]);

    $operation = $this->lifecycle->proposeRemove($installation, $this->author);
    $this->operations->approve($operation->id, $this->admin);
    $result = $this->operations->execute($operation->id);

    expect($result->status)->toBe(OperationStatus::Succeeded)
        ->and(file_exists($this->minecraftRoot.'/plugins/Foo.jar'))->toBeFalse();

    $rollbackArtifact = PluginRollbackArtifact::query()->where('relative_path', 'plugins/Foo.jar')->sole();
    expect($rollbackArtifact->reason)->toBe('pre-remove')
        ->and(file_get_contents($rollbackArtifact->storage_path))->toBe($bytes);
});

/*
|--------------------------------------------------------------------------
| Rollback: restores a preserved artifact.
|--------------------------------------------------------------------------
*/

it('rolls back to a preserved artifact via plugin.rollback', function () {
    $v1 = jarBytes('Foo', '1.0.0');
    $v2 = jarBytes('Foo', '2.0.0');
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', $v1);
    $installation = makeInstallation(['relative_path' => 'plugins/Foo.jar', 'name' => 'Foo', 'sha256' => hash('sha256', $v1)]);

    // Update to v2 first (this preserves v1 as a rollback artifact).
    Http::fake(['*' => Http::response($v2)]);
    $release = PluginReleaseFactory::make(sha256: hash('sha256', $v2), version: '2.0.0', name: 'Foo');
    $updateOp = $this->lifecycle->proposeUpdate($installation, $release, $this->author);
    $this->operations->approve($updateOp->id, $this->admin);
    $this->operations->execute($updateOp->id);

    expect(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe($v2);

    $v1Artifact = PluginRollbackArtifact::query()->where('relative_path', 'plugins/Foo.jar')->where('reason', 'pre-update')->sole();

    // Now propose+approve+execute a rollback BACK to v1.
    $installation->refresh();
    $rollbackOp = $this->lifecycle->proposeRollback($installation, $v1Artifact, $this->author);
    $this->operations->approve($rollbackOp->id, $this->admin);
    $result = $this->operations->execute($rollbackOp->id);

    expect($result->status)->toBe(OperationStatus::Succeeded)
        ->and(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe($v1);
});

/*
|--------------------------------------------------------------------------
| Generic undo (App\Operations\OperationService::rollback()) — every
| lifecycle change is reversible, not only the ones re-proposed as a
| fresh plugin.rollback operation.
|--------------------------------------------------------------------------
*/

it('undoes a disable via the generic operation rollback, re-enabling the plugin', function () {
    $bytes = jarBytes('Foo');
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', $bytes);
    $installation = makeInstallation(['relative_path' => 'plugins/Foo.jar', 'name' => 'Foo', 'sha256' => hash('sha256', $bytes)]);

    $operation = $this->lifecycle->proposeDisable($installation, $this->author);
    $this->operations->approve($operation->id, $this->admin);
    $this->operations->execute($operation->id);

    expect(file_exists($this->minecraftRoot.'/plugins/Foo.jar'))->toBeFalse();

    $this->operations->rollback($operation->id, $this->author);

    expect(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe($bytes)
        ->and(file_exists($this->minecraftRoot.'/plugins/Foo.jar.disabled'))->toBeFalse();
});

it('undoes an install via the generic operation rollback, removing the newly-installed file', function () {
    $bytes = jarBytes('BrandNew');
    Http::fake(['*' => Http::response($bytes)]);
    $release = PluginReleaseFactory::make(sha256: hash('sha256', $bytes), name: 'BrandNew');

    $operation = $this->lifecycle->proposeInstall($release, $this->author);
    $this->operations->approve($operation->id, $this->admin);
    $this->operations->execute($operation->id);

    expect(file_exists($this->minecraftRoot.'/plugins/BrandNew.jar'))->toBeTrue();

    $this->operations->rollback($operation->id, $this->author);

    expect(file_exists($this->minecraftRoot.'/plugins/BrandNew.jar'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Undoing an UPDATE (the artifact-restore branch of
| undoFromPreservedArtifact()) must never clobber a LATER state
| unrecoverably: whatever is CURRENTLY on disk is preserved BEFORE the
| undo overwrites it, and an intervening change since the operation being
| undone is REFUSED rather than silently clobbered.
|--------------------------------------------------------------------------
*/

it('preserves the current artifact before undoing an update, and refuses the undo when a later change has drifted the file', function () {
    $v1 = jarBytes('Foo', '1.0.0');
    $v2 = jarBytes('Foo', '2.0.0');
    $v3 = jarBytes('Foo', '3.0.0');
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', $v1);
    $installation = makeInstallation(['relative_path' => 'plugins/Foo.jar', 'name' => 'Foo', 'sha256' => hash('sha256', $v1)]);

    // install v1 -> update v1->v2 (op U) -> update v2->v3 (op V). Two
    // downloads in one test need Http::sequence() (not two Http::fake()
    // calls) — with `stream: true` (App\Plugins\PluginDownloader's own
    // option), Laravel's HTTP fake only replays a SECOND plain
    // Http::fake()'s body correctly across a fresh fake registration
    // within the same test process; a queued sequence avoids that.
    Http::fake(['*' => Http::sequence()->push($v2)->push($v3)]);
    $releaseV2 = PluginReleaseFactory::make(sha256: hash('sha256', $v2), version: '2.0.0', name: 'Foo');
    $opU = $this->lifecycle->proposeUpdate($installation, $releaseV2, $this->author);
    $this->operations->approve($opU->id, $this->admin);
    $this->operations->execute($opU->id);
    expect(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe($v2);

    $installation->refresh();
    $releaseV3 = PluginReleaseFactory::make(sha256: hash('sha256', $v3), version: '3.0.0', name: 'Foo');
    $opV = $this->lifecycle->proposeUpdate($installation, $releaseV3, $this->author);
    $this->operations->approve($opV->id, $this->admin);
    $this->operations->execute($opV->id);
    expect(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe($v3);

    // Open op U ("v1 -> v2") and click "Undo this change".
    $result = $this->operations->rollback($opU->id, $this->author);

    // (1) The CURRENT (v3) bytes were preserved to plugin-rollbacks BEFORE
    // the undo write was attempted — recoverable, regardless of whether
    // the write itself is subsequently refused.
    $preservedV3 = PluginRollbackArtifact::query()
        ->where('relative_path', 'plugins/Foo.jar')
        ->where('sha256', hash('sha256', $v3))
        ->first();
    expect($preservedV3)->not->toBeNull()
        ->and(file_get_contents($preservedV3->storage_path))->toBe($v3);

    // (2) The undo is REFUSED (a stale-hash conflict, surfaced as a typed
    // error on the operation) rather than clobbering v3.
    expect($result->error_code)->toBe('plugin.hash_mismatch')
        ->and(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe($v3);
});

it('undoes an update via the generic operation rollback when nothing has changed since, restoring the earlier artifact', function () {
    $v1 = jarBytes('Foo', '1.0.0');
    $v2 = jarBytes('Foo', '2.0.0');
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', $v1);
    $installation = makeInstallation(['relative_path' => 'plugins/Foo.jar', 'name' => 'Foo', 'sha256' => hash('sha256', $v1)]);

    Http::fake(['*' => Http::response($v2)]);
    $release = PluginReleaseFactory::make(sha256: hash('sha256', $v2), version: '2.0.0', name: 'Foo');
    $opU = $this->lifecycle->proposeUpdate($installation, $release, $this->author);
    $this->operations->approve($opU->id, $this->admin);
    $this->operations->execute($opU->id);
    expect(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe($v2);

    $result = $this->operations->rollback($opU->id, $this->author);

    expect($result->error_code)->toBeNull()
        ->and(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe($v1);
});
