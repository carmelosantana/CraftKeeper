<?php

use App\Ai\AiManager;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

/*
|--------------------------------------------------------------------------
| The task brief's own Step 1 test, verbatim — plus the real network
| guarantee.
|--------------------------------------------------------------------------
|
| Http::fake() only fakes Laravel's Http facade (Illuminate\Http\Client);
| carmelosantana/php-agents' providers talk to Symfony's HttpClient
| directly and never touch Illuminate\Http\Client at all (see
| docs/architecture/decisions.md, Task 16) — so Http::fake() alone cannot
| guarantee "no real network" for this scenario. It is kept below to stay
| textually faithful to the brief's own test. The actual no-real-network
| guarantee comes from binding App\Ai\AiManager with a
| Symfony\Component\HttpClient\MockHttpClient that always throws, exactly
| like every other AI test in this suite.
|
*/

it('keeps the application healthy when Ollama is offline', function () {
    Setting::put('ai.provider', 'ollama');
    Setting::put('ai.ollama.base_url', 'http://ollama:11434/v1');
    Setting::put('ai.ollama.model', 'llama3.2');

    Http::fake(['http://ollama:11434/*' => Http::failedConnection()]);

    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        fn (): never => throw new TransportException('Connection refused'),
    )));

    $this->actingAs($this->admin)->get('/assistant')
        ->assertOk()
        ->assertSee('AI is unavailable');
});

it('shows a distinct disabled state when no provider is configured at all', function () {
    $this->actingAs($this->admin)->get('/assistant')
        ->assertOk()
        ->assertSee('AI is disabled');
});

it('shows the assistant ready when the configured provider is reachable', function () {
    Setting::put('ai.provider', 'ollama');
    Setting::put('ai.ollama.base_url', 'http://ollama:11434/v1');
    Setting::put('ai.ollama.model', 'llama3.2');

    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        new MockResponse('{"models":[]}', ['http_code' => 200]),
    )));

    $response = $this->actingAs($this->admin)->get('/assistant')->assertOk();
    $response->assertInertia(fn ($page) => $page->where('status', 'ready'));
});

/*
|--------------------------------------------------------------------------
| An AI outage must not fail /up, configuration, or plugins.
|--------------------------------------------------------------------------
*/

it('does not fail /up when the AI provider is unreachable', function () {
    Setting::put('ai.provider', 'ollama');
    Setting::put('ai.ollama.base_url', 'http://ollama:11434/v1');
    Setting::put('ai.ollama.model', 'llama3.2');

    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        fn (): never => throw new TransportException('Connection refused'),
    )));

    $this->getJson('/up')->assertOk()->assertJsonPath('status', 'ok');
});

it('does not fail the configuration inventory when the AI provider is unreachable', function () {
    $minecraftRoot = TempMinecraftRoot::create();
    $dataRoot = TempMinecraftRoot::createDataRoot();
    config(['craftkeeper.minecraft_root' => $minecraftRoot, 'craftkeeper.data_root' => $dataRoot]);

    Setting::put('ai.provider', 'ollama');
    Setting::put('ai.ollama.base_url', 'http://ollama:11434/v1');
    Setting::put('ai.ollama.model', 'llama3.2');

    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        fn (): never => throw new TransportException('Connection refused'),
    )));

    $this->actingAs($this->admin)->get('/configurations')->assertOk();

    TempMinecraftRoot::destroy($minecraftRoot);
    TempMinecraftRoot::destroy($dataRoot);
});

it('does not fail the plugin inventory when the AI provider is unreachable', function () {
    $minecraftRoot = TempMinecraftRoot::create();
    $dataRoot = TempMinecraftRoot::createDataRoot();
    config(['craftkeeper.minecraft_root' => $minecraftRoot, 'craftkeeper.data_root' => $dataRoot]);

    Setting::put('ai.provider', 'ollama');
    Setting::put('ai.ollama.base_url', 'http://ollama:11434/v1');
    Setting::put('ai.ollama.model', 'llama3.2');

    app()->instance(AiManager::class, new AiManager(new MockHttpClient(
        fn (): never => throw new TransportException('Connection refused'),
    )));

    $this->actingAs($this->admin)->get('/plugins')->assertOk();

    TempMinecraftRoot::destroy($minecraftRoot);
    TempMinecraftRoot::destroy($dataRoot);
});
