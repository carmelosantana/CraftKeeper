<?php

use App\Models\Operation;
use App\Models\PluginInstallation;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    File::makeDirectory($this->minecraftRoot.'/plugins', 0755, true, true);
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function pluginsReadToken(): string
{
    return User::factory()->create()->createToken('reader', ['plugins:read'])->plainTextToken;
}

it('lists installed plugins, cursor-paginated', function () {
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', 'jar-bytes');
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'version' => '1.0.0',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);

    $this->withToken(pluginsReadToken())
        ->getJson('/api/v1/plugins')
        ->assertOk()
        ->assertJsonFragment(['relative_path' => 'plugins/Foo.jar']);
});

it('shows a single installed plugin by filename', function () {
    // Deliberately no physical file on disk: PluginInventoryService::
    // reconcile() only re-inspects (and can overwrite `name` on) files it
    // actually discovers on disk; a DB-only row is left untouched, marked
    // missing rather than re-derived — mirrors the equivalent web
    // App\Http\Controllers\PluginControllerTest convention.
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'version' => '1.0.0',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);

    $this->withToken(pluginsReadToken())
        ->getJson('/api/v1/plugins/Foo.jar')
        ->assertOk()
        ->assertJsonPath('data.name', 'Foo');
});

it('404s for an unknown plugin filename', function () {
    $this->withToken(pluginsReadToken())
        ->getJson('/api/v1/plugins/DoesNotExist.jar')
        ->assertNotFound();
});

it('proposes a plugin.disable operation with plugins:manage, without executing anything', function () {
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', 'jar-bytes');
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'version' => '1.0.0',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);
    $token = User::factory()->create()->createToken('manager', ['plugins:manage'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/plugins/Foo.jar/disable', [])
        ->assertCreated();

    $response->assertJsonPath('data.status', 'proposed')
        ->assertJsonPath('data.type', 'plugin.disable');

    // Still enabled on disk/DB — nothing executed, only proposed.
    expect(PluginInstallation::query()->where('relative_path', 'plugins/Foo.jar')->first()->enabled)->toBeTrue();
});

it('proposes a plugin.remove operation with plugins:manage', function () {
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', 'jar-bytes');
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'version' => '1.0.0',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);
    $token = User::factory()->create()->createToken('manager', ['plugins:manage'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/plugins/Foo.jar/remove', [])
        ->assertCreated()
        ->assertJsonPath('data.type', 'plugin.remove');
});

it('a plugins:read-only token cannot disable or remove a plugin', function () {
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', 'jar-bytes');
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'version' => '1.0.0',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);

    $token = pluginsReadToken();

    $this->withToken($token)->postJson('/api/v1/plugins/Foo.jar/disable', [])->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/plugins/Foo.jar/remove', [])->assertForbidden();

    expect(Operation::query()->count())->toBe(0);
});

it('repeated Idempotency-Key on plugin disable returns the original proposal', function () {
    file_put_contents($this->minecraftRoot.'/plugins/Foo.jar', 'jar-bytes');
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Foo.jar',
        'name' => 'Foo',
        'version' => '1.0.0',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);
    $token = User::factory()->create()->createToken('manager', ['plugins:manage'])->plainTextToken;

    $first = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'disable-once'])
        ->postJson('/api/v1/plugins/Foo.jar/disable', [])
        ->assertCreated()
        ->json('data');

    $second = $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'disable-once'])
        ->postJson('/api/v1/plugins/Foo.jar/disable', [])
        ->assertCreated()
        ->json('data');

    expect($second['id'])->toBe($first['id']);
    expect(Operation::query()->where('type', 'plugin.disable')->count())->toBe(1);
});
