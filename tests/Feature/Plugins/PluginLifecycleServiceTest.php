<?php

use App\Models\Operation;
use App\Models\PluginInstallation;
use App\Models\PluginOperationPlan;
use App\Models\ServerSample;
use App\Models\User;
use App\Operations\OperationAuthor;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use App\Plugins\Exceptions\PluginChecksumMismatch;
use App\Plugins\PluginLifecycleService;
use App\Plugins\PluginUploadService;
use Illuminate\Http\UploadedFile;
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
    $this->service = app(PluginLifecycleService::class);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function makePluginInstallation(array $attributes = []): PluginInstallation
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

function realJarBytes(string $name = 'ExamplePlugin', string $version = '1.0.0'): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ck-jar-').'.jar';
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: {$name}\nversion: '{$version}'\n")
        ->writeTo($tmp);
    $bytes = file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

/*
|--------------------------------------------------------------------------
| Install: propose builds a plan and an Operation; the checksum gate
| happens BEFORE any Operation exists.
|--------------------------------------------------------------------------
*/

it('proposes a plugin.install operation with a rich plan, relocating quarantine to the operation id', function () {
    $bytes = realJarBytes();
    Http::fake(['*' => Http::response($bytes)]);

    $release = PluginReleaseFactory::make(sha256: hash('sha256', $bytes), name: 'ExamplePlugin');

    $operation = $this->service->proposeInstall($release, $this->author);

    expect($operation->type)->toBe(OperationType::PluginInstall)
        ->and($operation->status)->toBe(OperationStatus::Proposed);

    $plan = PluginOperationPlan::forOperation($operation->id);

    expect($plan)->not->toBeNull()
        ->and($plan->kind)->toBe('install')
        ->and($plan->target_relative_path)->toBe('plugins/ExamplePlugin.jar')
        ->and($plan->verified_sha256)->toBe(hash('sha256', $bytes))
        ->and($plan->plan['restartRequired'])->toBeTrue()
        ->and($plan->plan['fileChanges'])->toBe(['Create plugins/ExamplePlugin.jar'])
        ->and($plan->quarantine_path)->toContain($operation->id)
        ->and(is_file($plan->quarantine_path))->toBeTrue();

    // Never touched /minecraft before approval.
    expect(glob($this->minecraftRoot.'/plugins/*') ?: [])->toBe([]);
});

it('never creates an Operation at all when the download checksum does not match', function () {
    Http::fake(['*' => Http::response('not-the-real-bytes')]);

    $release = PluginReleaseFactory::make();

    expect(fn () => $this->service->proposeInstall($release, $this->author))
        ->toThrow(PluginChecksumMismatch::class);

    expect(Operation::query()->count())->toBe(0)
        ->and(PluginOperationPlan::query()->count())->toBe(0)
        ->and(glob($this->minecraftRoot.'/plugins/*') ?: [])->toBe([])
        ->and(glob($this->dataRoot.'/quarantine/*') ?: [])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Update: targets the EXISTING installation's path, not a derived one.
|--------------------------------------------------------------------------
*/

it('proposes a plugin.update operation targeting the existing installation path', function () {
    $installation = makePluginInstallation([
        'relative_path' => 'plugins/ExamplePlugin.jar',
        'name' => 'ExamplePlugin',
        'version' => '1.0.0',
    ]);

    $bytes = realJarBytes(version: '1.1.0');
    Http::fake(['*' => Http::response($bytes)]);
    $release = PluginReleaseFactory::make(sha256: hash('sha256', $bytes), version: '1.1.0');

    $operation = $this->service->proposeUpdate($installation, $release, $this->author);

    expect($operation->type)->toBe(OperationType::PluginUpdate);

    $plan = PluginOperationPlan::forOperation($operation->id);
    expect($plan->kind)->toBe('update')
        ->and($plan->target_relative_path)->toBe('plugins/ExamplePlugin.jar');
});

/*
|--------------------------------------------------------------------------
| Upload: inspection findings are available BEFORE any proposal exists.
|--------------------------------------------------------------------------
*/

it('shows inspection findings for an uploaded jar before any install proposal is created', function () {
    $bytes = realJarBytes('UploadedPlugin');
    $file = UploadedFile::fake()->createWithContent('UploadedPlugin.jar', $bytes);

    $artifact = app(PluginUploadService::class)->store($file);

    // Findings available with zero Operations/plans created.
    $inspected = $this->service->inspectQuarantinedUpload($artifact);

    expect($inspected->name)->toBe('UploadedPlugin')
        ->and(Operation::query()->count())->toBe(0)
        ->and(PluginOperationPlan::query()->count())->toBe(0);

    // Only NOW, on a separate confirm step, is a proposal created.
    $operation = $this->service->proposeUpload($artifact, $this->author);

    expect($operation->type)->toBe(OperationType::PluginInstall);
    $plan = PluginOperationPlan::forOperation($operation->id);
    expect($plan->target_relative_path)->toBe('plugins/UploadedPlugin.jar')
        ->and($plan->source)->toBe('Manual');
});

it('re-resolves a quarantined artifact by token with a freshly recomputed hash, never trusting a client-supplied one', function () {
    $bytes = realJarBytes();
    $file = UploadedFile::fake()->createWithContent('X.jar', $bytes);
    $artifact = app(PluginUploadService::class)->store($file);

    $resolved = $this->service->resolveQuarantinedArtifact($artifact->token);

    expect($resolved->sha256)->toBe(hash('sha256', $bytes))
        ->and($resolved->absolutePath)->toBe($artifact->absolutePath);
});

/*
|--------------------------------------------------------------------------
| Disable / Remove / Rollback: no quarantine involved.
|--------------------------------------------------------------------------
*/

it('proposes a plugin.disable operation with no quarantine artifact', function () {
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', 'jar-bytes');
    $installation = makePluginInstallation(['relative_path' => 'plugins/Foo.jar', 'name' => 'Foo']);

    $operation = $this->service->proposeDisable($installation, $this->author);

    expect($operation->type)->toBe(OperationType::PluginDisable);
    $plan = PluginOperationPlan::forOperation($operation->id);
    expect($plan->kind)->toBe('disable')
        ->and($plan->quarantine_path)->toBeNull();
});

it('proposes a plugin.remove operation with no quarantine artifact', function () {
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', 'jar-bytes');
    $installation = makePluginInstallation(['relative_path' => 'plugins/Foo.jar', 'name' => 'Foo']);

    $operation = $this->service->proposeRemove($installation, $this->author);

    expect($operation->type)->toBe(OperationType::PluginRemove);
    $plan = PluginOperationPlan::forOperation($operation->id);
    expect($plan->kind)->toBe('remove');
});

/*
|--------------------------------------------------------------------------
| Quarantine cleanup: deleted once the operation reaches a terminal
| state — including Rejected, via OperationService::reject().
|--------------------------------------------------------------------------
*/

it('deletes the quarantined artifact when a proposed install is rejected, without deleting the plan row', function () {
    $bytes = realJarBytes();
    Http::fake(['*' => Http::response($bytes)]);
    $release = PluginReleaseFactory::make(sha256: hash('sha256', $bytes));

    $operation = $this->service->proposeInstall($release, $this->author);
    $plan = PluginOperationPlan::forOperation($operation->id);
    $quarantinePath = $plan->quarantine_path;

    expect(is_file($quarantinePath))->toBeTrue();

    app(OperationService::class)->reject($operation->id, $this->admin, 'Changed my mind.');

    expect(is_file($quarantinePath))->toBeFalse()
        ->and(PluginOperationPlan::forOperation($operation->id))->not->toBeNull()
        ->and(PluginOperationPlan::forOperation($operation->id)->quarantine_path)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Restart-required stays true until a server start is OBSERVED — never
| fabricated just because RCON currently happens to be reachable.
|--------------------------------------------------------------------------
*/

it('reports restart not observed when no server samples exist at all', function () {
    $operation = Operation::factory()->status(OperationStatus::Succeeded)->ofType(OperationType::PluginInstall)->create([
        'finished_at' => now()->subMinute(),
    ]);

    expect($this->service->isRestartObserved($operation))->toBeFalse();
});

it('does not fabricate a restart just because RCON is currently reachable with no observed down transition', function () {
    $operation = Operation::factory()->status(OperationStatus::Succeeded)->ofType(OperationType::PluginInstall)->create([
        'finished_at' => now()->subMinute(),
    ]);

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => true,
        'player_count' => 0,
        'player_names' => [],
    ]);

    expect($this->service->isRestartObserved($operation))->toBeFalse();
});

it('reports restart observed once RCON is seen going unreachable then reachable again after the operation finished', function () {
    $operation = Operation::factory()->status(OperationStatus::Succeeded)->ofType(OperationType::PluginInstall)->create([
        'finished_at' => now()->subMinutes(5),
    ]);

    ServerSample::query()->create([
        'sampled_at' => now()->subMinutes(3),
        'rcon_reachable' => false,
        'player_count' => null,
        'player_names' => null,
        'error_reason' => 'restarting',
    ]);

    ServerSample::query()->create([
        'sampled_at' => now()->subMinute(),
        'rcon_reachable' => true,
        'player_count' => 0,
        'player_names' => [],
    ]);

    expect($this->service->isRestartObserved($operation))->toBeTrue();
});
