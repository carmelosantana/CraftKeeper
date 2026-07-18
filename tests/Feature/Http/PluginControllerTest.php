<?php

use App\Models\Operation;
use App\Models\PluginInstallation;
use App\Models\PluginOperationPlan;
use App\Models\PluginRollbackArtifact;
use App\Models\User;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\fixtures\plugins\JarFixtureBuilder;
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
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function pluginJarBytes(string $name, string $version = '1.0.0'): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ck-jar-').'.jar';
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: {$name}\nversion: '{$version}'\n")
        ->writeTo($tmp);
    $bytes = file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function fakeCraftKeeperCatalog(string $downloadUrl, string $sha256, string $name = 'EssentialsX', string $version = '1.0.0'): void
{
    $catalogUrl = (string) config('catalog.sources.craftkeeper.url');

    Http::fake([
        $catalogUrl => Http::response([
            'catalogVersion' => '1.0.0',
            'generatedAt' => '2026-07-01T00:00:00Z',
            'plugins' => [[
                'slug' => strtolower($name),
                'name' => $name,
                'description' => 'A test plugin.',
                'projectUrl' => 'https://example.test/'.strtolower($name),
                'license' => 'MIT',
                'sourceRepository' => 'https://example.test/repo',
                'releases' => [[
                    'version' => $version,
                    'minecraftVersions' => ['1.21.8'],
                    'platforms' => ['paper'],
                    'dependencies' => [],
                    'downloadUrl' => $downloadUrl,
                    'sha256' => $sha256,
                    'releasedAt' => '2026-06-01T00:00:00Z',
                    'withdrawn' => false,
                ]],
            ]],
        ]),
        $downloadUrl => Http::response(''), // overridden per-test below via a second fake() call ordering
        '*' => Http::response('', 500),
    ]);
}

/*
|--------------------------------------------------------------------------
| Index / Discover pages render
|--------------------------------------------------------------------------
*/

it('renders the installed plugins index', function () {
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);

    $response = $this->actingAs($this->admin)->get('/plugins');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('plugins/Index')
        ->has('plugins', 1)
        ->where('plugins.0.name', 'Foo'));
});

it('renders discover results and never sends a raw download URL or checksum to the browser', function () {
    $bytes = pluginJarBytes('EssentialsX');
    $downloadUrl = 'https://example.test/EssentialsX-1.0.0.jar';
    fakeCraftKeeperCatalog($downloadUrl, hash('sha256', $bytes));

    $response = $this->actingAs($this->admin)->get('/plugins/discover?q=Essentials');

    $response->assertOk();
    $response->assertInertia(function ($page) {
        $page->component('plugins/Discover');
        $items = $page->toArray()['props']['items'];
        expect($items)->not->toBeEmpty();

        foreach ($items as $item) {
            expect($item)->not->toHaveKey('downloadUrl')
                ->and($item)->not->toHaveKey('sha256');
        }
    });
});

/*
|--------------------------------------------------------------------------
| Install: identity in, server-resolved release out; checksum gate holds
| at the HTTP layer too.
|--------------------------------------------------------------------------
*/

it('installs a plugin end to end via the controller: identity in, atomic install out', function () {
    $bytes = pluginJarBytes('EssentialsX');
    $downloadUrl = 'https://example.test/EssentialsX-1.0.0.jar';

    Http::fake([
        (string) config('catalog.sources.craftkeeper.url') => Http::response([
            'catalogVersion' => '1.0.0',
            'generatedAt' => '2026-07-01T00:00:00Z',
            'plugins' => [[
                'slug' => 'essentialsx',
                'name' => 'EssentialsX',
                'description' => 'A test plugin.',
                'projectUrl' => 'https://example.test/essentialsx',
                'license' => 'MIT',
                'sourceRepository' => 'https://example.test/repo',
                'releases' => [[
                    'version' => '1.0.0',
                    'minecraftVersions' => ['1.21.8'],
                    'platforms' => ['paper'],
                    'dependencies' => [],
                    'downloadUrl' => $downloadUrl,
                    'sha256' => hash('sha256', $bytes),
                    'releasedAt' => '2026-06-01T00:00:00Z',
                    'withdrawn' => false,
                ]],
            ]],
        ]),
        $downloadUrl => Http::response($bytes),
        '*' => Http::response('', 500),
    ]);

    $response = $this->actingAs($this->admin)->post('/plugins/install', [
        'source' => 'Catalog',
        'projectId' => 'essentialsx',
        'version' => '1.0.0',
    ]);

    $operation = Operation::query()->sole();
    $response->assertRedirect('/plugins/operations/'.$operation->id);
    expect($operation->type)->toBe(OperationType::PluginInstall)
        ->and($operation->status)->toBe(OperationStatus::Proposed);

    // Not installed until approved.
    expect(glob($this->minecraftRoot.'/plugins/*.jar'))->toBe([]);

    $approveResponse = $this->actingAs($this->admin)->post("/plugins/operations/{$operation->id}/approve");
    $approveResponse->assertRedirect('/plugins/operations/'.$operation->id);

    expect(file_get_contents($this->minecraftRoot.'/plugins/EssentialsX.jar'))->toBe($bytes);
});

it('never accepts a client-supplied download URL or checksum for install — only an identity is read from the request', function () {
    // No `downloadUrl`/`sha256` field is even validated by proposeInstall();
    // supplying one has zero effect, since the release is always
    // re-resolved server-side by (source, projectId, version).
    $bytes = pluginJarBytes('EssentialsX');
    $downloadUrl = 'https://example.test/EssentialsX-1.0.0.jar';

    Http::fake([
        (string) config('catalog.sources.craftkeeper.url') => Http::response([
            'catalogVersion' => '1.0.0',
            'generatedAt' => '2026-07-01T00:00:00Z',
            'plugins' => [[
                'slug' => 'essentialsx',
                'name' => 'EssentialsX',
                'description' => 'A test plugin.',
                'projectUrl' => 'https://example.test/essentialsx',
                'license' => 'MIT',
                'sourceRepository' => 'https://example.test/repo',
                'releases' => [[
                    'version' => '1.0.0',
                    'minecraftVersions' => ['1.21.8'],
                    'platforms' => ['paper'],
                    'dependencies' => [],
                    'downloadUrl' => $downloadUrl,
                    'sha256' => hash('sha256', $bytes),
                    'releasedAt' => '2026-06-01T00:00:00Z',
                    'withdrawn' => false,
                ]],
            ]],
        ]),
        $downloadUrl => Http::response($bytes),
        'https://attacker.example/evil.jar' => Http::response('malicious-bytes'),
        '*' => Http::response('', 500),
    ]);

    $this->actingAs($this->admin)->post('/plugins/install', [
        'source' => 'Catalog',
        'projectId' => 'essentialsx',
        'version' => '1.0.0',
        // An attacker-controlled downloadUrl/sha256 smuggled in — must be
        // silently ignored, never reaching PluginDownloader.
        'downloadUrl' => 'https://attacker.example/evil.jar',
        'sha256' => str_repeat('f', 64),
    ]);

    $plan = PluginOperationPlan::query()->sole();
    expect($plan->verified_sha256)->toBe(hash('sha256', $bytes));
});

/*
|--------------------------------------------------------------------------
| Upload: findings shown before proposal; guarded actions.
|--------------------------------------------------------------------------
*/

it('shows upload findings, then proposes an install only on the second, explicit request', function () {
    $bytes = pluginJarBytes('UploadedPlugin');
    $file = UploadedFile::fake()->createWithContent('UploadedPlugin.jar', $bytes);

    $response = $this->actingAs($this->admin)->post('/plugins/upload', ['file' => $file]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('plugins/Upload')
        ->where('findings.name', 'UploadedPlugin'));

    expect(Operation::query()->count())->toBe(0);

    $token = $response->viewData('page')['props']['findings']['token'];

    $proposeResponse = $this->actingAs($this->admin)->post("/plugins/upload/{$token}/propose");
    $operation = Operation::query()->sole();
    $proposeResponse->assertRedirect('/plugins/operations/'.$operation->id);
});

/*
|--------------------------------------------------------------------------
| Guards: only plugin.* operations are reachable through this controller.
|--------------------------------------------------------------------------
*/

it('404s when approving a non-plugin operation through the plugin controller', function () {
    $operation = Operation::factory()->status(OperationStatus::Proposed)->ofType(OperationType::ConfigApply)->create();

    $this->actingAs($this->admin)->post("/plugins/operations/{$operation->id}/approve")->assertNotFound();
});

it('404s when approving an already-approved plugin operation', function () {
    $operation = Operation::factory()->status(OperationStatus::Approved)->ofType(OperationType::PluginRemove)->create();

    $this->actingAs($this->admin)->post("/plugins/operations/{$operation->id}/approve")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Rollback via the controller restores a previously preserved artifact.
|--------------------------------------------------------------------------
*/

it('proposes and executes a rollback to a preserved artifact through the controller', function () {
    $bytes = pluginJarBytes('Foo');
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', $bytes);
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'sha256' => hash('sha256', $bytes),
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);

    $artifact = PluginRollbackArtifact::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'storage_path' => $this->dataRoot.'/plugin-rollbacks/preserved.jar',
        'sha256' => hash('sha256', 'old-version-bytes'),
        'size_bytes' => strlen('old-version-bytes'),
        'reason' => 'pre-update',
    ]);
    File::ensureDirectoryExists($this->dataRoot.'/plugin-rollbacks');
    file_put_contents($artifact->storage_path, 'old-version-bytes');

    $response = $this->actingAs($this->admin)->post('/plugins/Foo.jar/rollback', ['rollback_artifact_id' => $artifact->id]);

    $operation = Operation::query()->sole();
    $response->assertRedirect('/plugins/operations/'.$operation->id);

    $this->actingAs($this->admin)->post("/plugins/operations/{$operation->id}/approve");

    expect(file_get_contents($this->minecraftRoot.'/plugins/Foo.jar'))->toBe('old-version-bytes');
});

it('refuses to apply a rollback artifact that belongs to a different plugin', function () {
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);

    $otherArtifact = PluginRollbackArtifact::query()->create([
        'relative_path' => 'plugins/SomeOtherPlugin.jar',
        'storage_path' => '/dev/null',
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 0,
        'reason' => 'pre-remove',
    ]);

    $this->actingAs($this->admin)->post('/plugins/Foo.jar/rollback', ['rollback_artifact_id' => $otherArtifact->id]);

    expect(Operation::query()->count())->toBe(0);
});
