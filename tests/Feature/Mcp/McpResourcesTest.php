<?php

use App\Console\RconCommandService;
use App\Models\McpGrant;
use App\Models\PluginInstallation;
use App\Operations\OperationAuthor;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CallsMcp;
use Tests\Support\TempMinecraftRoot;

uses(CallsMcp::class);

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

it('reads a bounded, honest server status resource with server:read — no fabricated player count', function () {
    $grant = McpGrant::factory()->withScopes(['server:read'])->create();

    $result = $this->readMcpResource($grant, 'craftkeeper://server/status')->assertOk();

    expect($result['rcon']['available'])->toBeFalse()
        ->and($result['rcon']['player_count'])->toBeNull()
        ->and($result['rcon']['reason'])->not->toBeNull();
});

it('lists a bounded config file inventory with config:read — metadata only, never content', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $result = $this->readMcpResource($grant, 'craftkeeper://config/files')->assertOk();

    expect($result['files'])->toHaveCount(1)
        ->and($result['files'][0]['path'])->toBe('server.properties')
        ->and($result['files'][0])->not->toHaveKey('contents');
});

it('reads REDACTED config file content via the encoded-path template, never the raw secret', function () {
    $contents = "rcon.password=actual-secret-value\nmotd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $uri = 'craftkeeper://config/files/'.rawurlencode('server.properties');
    $result = $this->readMcpResource($grant, $uri)->assertOk();

    expect($result['path'])->toBe('server.properties')
        ->and($result['contents'])->toContain('motd=hi')
        ->and($result['contents'])->not->toContain('actual-secret-value');
});

it('reads a nested plugin config path via its URL-encoded content_uri', function () {
    File::makeDirectory($this->minecraftRoot.'/plugins/Foo', 0755, true, true);
    file_put_contents($this->minecraftRoot.'/plugins/Foo/config.yml', "enabled: true\n");
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $inventory = $this->readMcpResource($grant, 'craftkeeper://config/files')->assertOk();
    $entry = collect($inventory['files'])->firstWhere('path', 'plugins/Foo/config.yml');

    expect($entry)->not->toBeNull();

    $result = $this->readMcpResource($grant, $entry['content_uri'])->assertOk();
    expect($result['contents'])->toContain('enabled: true');
});

it('truncates config file content after redaction, bounding the response size', function () {
    $big = str_repeat("padding-line=filler-value-to-pad-this-out\n", 500);
    file_put_contents($this->minecraftRoot.'/server.properties', $big.'rcon.password=actual-secret-value'."\n");
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $uri = 'craftkeeper://config/files/'.rawurlencode('server.properties');
    $result = $this->readMcpResource($grant, $uri)->assertOk();

    expect($result['truncated'])->toBeTrue()
        ->and(mb_strlen($result['contents']))->toBeLessThanOrEqual(8000)
        ->and($result['contents'])->not->toContain('actual-secret-value');
});

it('lists a bounded plugin inventory with plugins:read', function () {
    // Deliberately no physical file on disk: App\Plugins\
    // PluginInventoryService::reconcile() only re-inspects (and can
    // overwrite `name` on) files it actually discovers on disk — a
    // DB-only row is left untouched, exactly like Task 17's own
    // tests/Feature/Api/V1/PluginApiTest.php ("shows a single installed
    // plugin by filename") establishes for the identical situation.
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
    $grant = McpGrant::factory()->withScopes(['plugins:read'])->create();

    $result = $this->readMcpResource($grant, 'craftkeeper://plugins')->assertOk();

    expect($result['plugins'])->toHaveCount(1)
        ->and($result['plugins'][0]['name'])->toBe('Foo');
});

it('lists recent activity with activity:read — a secret-shaped rcon command is already display-redacted', function () {
    $author = OperationAuthor::user(1);
    app(RconCommandService::class)->proposeCommand('login mySuperSecretPass123', $author);

    $grant = McpGrant::factory()->withScopes(['activity:read'])->create();

    $result = $this->readMcpResource($grant, 'craftkeeper://activity')->assertOk();

    expect($result['operations'])->toHaveCount(1);
    $target = $result['operations'][0]['target'];
    expect($target)->not->toContain('mySuperSecretPass123')
        ->and($target)->toContain('login');
});

it('bounds the activity resource to the most recent items', function () {
    $author = OperationAuthor::user(1);

    for ($i = 0; $i < 25; $i++) {
        app(RconCommandService::class)->proposeCommand('list', $author);
    }

    $grant = McpGrant::factory()->withScopes(['activity:read'])->create();

    $result = $this->readMcpResource($grant, 'craftkeeper://activity')->assertOk();

    expect($result['operations'])->toHaveCount(20);
});
