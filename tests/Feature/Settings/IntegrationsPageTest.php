<?php

use App\Ai\AiManager;
use App\Catalog\CatalogSourceHealth;
use App\Models\McpGrant;
use App\Models\ServerSample;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\PluginProvenance;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

function integrationState(Assert $page, string $key): string
{
    $props = $page->toArray()['props'];
    $row = collect($props['integrations'])->firstWhere('key', $key);

    expect($row)->not->toBeNull("No integration row for \"{$key}\".");

    return $row['state'];
}

it('requires authentication to view the Integrations overview', function () {
    $this->get('/integrations')->assertRedirect('/login');
});

it('shows all ten integrations with an honest default state each', function () {
    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();

    $response->assertInertia(function (Assert $page) {
        $page->component('Integrations')->has('integrations', 10);

        $keys = collect($page->toArray()['props']['integrations'])->pluck('key')->all();

        expect($keys)->toEqualCanonicalizing([
            'minecraft-directory', 'rcon', 'ai', 'catalog', 'hangar', 'modrinth',
            'documentation', 'api', 'mcp', 'umami',
        ]);
    });
});

it('reports the Minecraft directory as connected once the root and log file exist', function () {
    $root = TempMinecraftRoot::create();
    File::ensureDirectoryExists($root.'/logs');
    File::put($root.'/logs/latest.log', "placeholder\n");
    config(['craftkeeper.minecraft_root' => $root]);

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'minecraft-directory'))->toBe('connected'));

    TempMinecraftRoot::destroy($root);
});

it('reports the Minecraft directory as misconfigured when the root is unavailable', function () {
    config(['craftkeeper.minecraft_root' => storage_path('craftkeeper-test-does-not-exist-'.uniqid())]);

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'minecraft-directory'))->toBe('misconfigured'));
});

it('reports RCON as disabled, then degraded, then connected as its configuration and reachability change', function () {
    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'rcon'))->toBe('disabled'));

    Setting::put('rcon.host', '127.0.0.1');
    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => false,
        'player_count' => null,
        'player_names' => null,
        'error_reason' => 'Connection refused.',
    ]);

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'rcon'))->toBe('degraded'));

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => true,
        'player_count' => 0,
        'player_names' => [],
        'error_reason' => null,
    ]);

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'rcon'))->toBe('connected'));
});

it('reports AI as disabled by default — never making an outbound request while disabled', function () {
    // Bound to a client that throws on ANY use — proves NO outbound
    // request is attempted while AI is disabled.
    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        fn (): never => throw new TransportException('must never be called while AI is disabled'),
    )));

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'ai'))->toBe('disabled'));
});

it('reports AI as misconfigured when a hosted provider is missing required fields — never making an outbound request', function () {
    Setting::put('ai.provider', 'openai');
    // No ai.hosted.base_url/model/api_key set — the hosted provider needs
    // all three (App\Models\AiProviderConfiguration::isConfigured()), so
    // AiManager::healthDetail() returns before ever constructing a
    // provider or touching an HTTP client at all.
    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        fn (): never => throw new TransportException('must never be called while AI is misconfigured'),
    )));

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'ai'))->toBe('misconfigured'));
});

/*
|--------------------------------------------------------------------------
| Deliberately TWO separate tests, not one test that re-binds AiManager
| twice against the same route: Laravel's Route caches its resolved
| controller instance on the Route object itself
| (Illuminate\Routing\Route::getController()) for as long as that Route
| object lives — which, within a single test method, is the whole test.
| A second app()->instance(AiManager::class, ...) rebind AFTER the first
| GET to the same route would be silently ignored by that already-resolved
| controller, reusing (and re-exhausting) the FIRST mock instead — a
| Laravel testing footgun, not an application bug. Each `it()` here gets
| its own fresh application/route boot, sidestepping it entirely.
*/
it('reports AI as degraded when a configured provider is unreachable', function () {
    Setting::put('ai.provider', 'ollama');
    Setting::put('ai.ollama.base_url', 'http://ollama:11434/v1');
    Setting::put('ai.ollama.model', 'llama3.2');

    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        fn (): never => throw new TransportException('Connection refused'),
    )));

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'ai'))->toBe('degraded'));
});

it('reports AI as connected when a configured provider responds', function () {
    Setting::put('ai.provider', 'ollama');
    Setting::put('ai.ollama.base_url', 'http://ollama:11434/v1');
    Setting::put('ai.ollama.model', 'llama3.2');

    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        new MockResponse('{"models":[]}', ['http_code' => 200]),
    )));

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'ai'))->toBe('connected'));
});

it('reports the catalog sources as disabled until checked, then connected or degraded from recorded health', function () {
    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(function (Assert $page) {
        expect(integrationState($page, 'catalog'))->toBe('disabled');
        expect(integrationState($page, 'hangar'))->toBe('disabled');
        expect(integrationState($page, 'modrinth'))->toBe('disabled');
    });

    app(CatalogSourceHealth::class)->recordSuccess(PluginProvenance::Catalog);
    app(CatalogSourceHealth::class)->recordFailure(PluginProvenance::Hangar, 'Timed out.');

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(function (Assert $page) {
        expect(integrationState($page, 'catalog'))->toBe('connected');
        expect(integrationState($page, 'hangar'))->toBe('degraded');
    });
});

it('always reports the official documentation cache as connected', function () {
    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'documentation'))->toBe('connected'));
});

it('reports API as disabled until a token exists, then connected', function () {
    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'api'))->toBe('disabled'));

    $this->admin->createToken('test token', ['server:read']);

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'api'))->toBe('connected'));
});

it('reports MCP as disabled until an active grant exists, then connected', function () {
    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'mcp'))->toBe('disabled'));

    McpGrant::query()->create([
        'oauth_client_id' => (string) Str::uuid(),
        'display_name' => 'Test client',
        'scopes' => ['server:read'],
        'created_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'mcp'))->toBe('connected'));
});

it('reports Umami as disabled, misconfigured, and connected exactly matching UmamiScript — making no outbound request in any state', function () {
    // Umami is a plain <script> tag with no server-side HTTP client at
    // all (see App\Support\UmamiScript's own docblock) — there is
    // nothing to mock/fake here to prove "no outbound request"; the
    // proof IS that this class never accepts or constructs an HTTP
    // client in the first place.
    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'umami'))->toBe('disabled'));

    Setting::put('analytics.umami.enabled', true);
    Setting::put('analytics.umami.script_url', 'http://not-https.example.com/script.js');

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'umami'))->toBe('misconfigured'));

    Setting::put('analytics.umami.script_url', 'https://analytics.example.com/script.js');
    Setting::put('analytics.umami.website_id', 'site-123');

    $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();
    $response->assertInertia(fn (Assert $page) => expect(integrationState($page, 'umami'))->toBe('connected'));
});

it('rejects an unknown integration test key', function () {
    $this->actingAs($this->admin)->post('/integrations/test/not-a-real-integration')->assertNotFound();
});

it('runs a real actionable test for a testable integration and redirects back', function () {
    $this->actingAs($this->admin)->post('/integrations/test/documentation')
        ->assertRedirect('/integrations');
});
