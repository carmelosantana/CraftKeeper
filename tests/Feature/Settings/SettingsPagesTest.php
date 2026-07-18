<?php

use App\Models\Secret;
use App\Models\Setting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

it('requires authentication for the settings index', function () {
    $this->get('/settings')->assertRedirect('/login');
});

it('renders the settings index with all nine sections', function () {
    $this->actingAs($this->admin)->get('/settings')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings')
            ->has('sections', 9)
            ->has('summary'),
        );
});

it('renders the server settings page and persists changes', function () {
    $this->actingAs($this->admin)->get('/settings/server')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/server'));

    $this->actingAs($this->admin)->put('/settings/server', [
        'minecraft_path' => '/minecraft',
        'rcon_host' => '127.0.0.1',
        'rcon_port' => '25575',
        'rcon_password' => 'a-real-password',
    ])->assertRedirect('/settings/server');

    expect(Setting::get('minecraft.server_path'))->toBe('/minecraft')
        ->and(Setting::get('rcon.host'))->toBe('127.0.0.1')
        ->and(Secret::configured('rcon.password'))->toBeTrue();
});

it('never flashes the RCON password into the session on a validation failure', function () {
    $response = $this->actingAs($this->admin)
        ->from('/settings/server')
        ->put('/settings/server', [
            'rcon_port' => 'not-a-number',
            'rcon_password' => 'super-secret-should-never-flash',
        ]);

    $response->assertRedirect('/settings/server');
    expect(session('errors'))->not->toBeNull();
    expect(old('rcon_password'))->toBeNull();
});

it('renders the AI settings page and persists changes without ever re-rendering the stored API key', function () {
    Secret::put('ai.api_key', 'previously-stored-key');

    $this->actingAs($this->admin)->get('/settings/ai')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('hostedApiKeyConfigured', true),
        );

    $this->actingAs($this->admin)->put('/settings/ai', [
        'provider' => 'ollama',
        'ollama_base_url' => 'http://ollama:11434/v1',
        'ollama_model' => 'llama3.2',
    ])->assertRedirect('/settings/ai');

    expect(Setting::get('ai.provider'))->toBe('ollama')
        ->and(Setting::get('ai.ollama.base_url'))->toBe('http://ollama:11434/v1');
});

it('never flashes the AI API key into the session on a validation failure', function () {
    // provider is capped at max:100 — an oversized value is a real,
    // reliable validation failure (Laravel's boolean rule is too
    // permissive about string forms to use for this).
    $response = $this->actingAs($this->admin)
        ->from('/settings/ai')
        ->put('/settings/ai', [
            'provider' => str_repeat('x', 500),
            'hosted_api_key' => 'super-secret-ai-key-should-never-flash',
        ]);

    $response->assertRedirect('/settings/ai');
    expect(session('errors'))->not->toBeNull();
    expect(old('hosted_api_key'))->toBeNull();
});

it('renders the analytics settings page and persists changes', function () {
    $this->actingAs($this->admin)->get('/settings/analytics')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/analytics')
            ->where('enabled', false)
            ->where('active', false),
        );

    $this->actingAs($this->admin)->put('/settings/analytics', [
        'enabled' => true,
        'script_url' => 'https://analytics.example.com/script.js',
        'website_id' => 'site-123',
    ])->assertRedirect('/settings/analytics');

    expect(Setting::get('analytics.umami.enabled'))->toBe('1')
        ->and(Setting::get('analytics.umami.script_url'))->toBe('https://analytics.example.com/script.js');

    $this->actingAs($this->admin)->get('/settings/analytics')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('active', true));
});

it('renders the advanced settings page with real environment info', function () {
    $this->actingAs($this->admin)->get('/settings/advanced')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/advanced')
            ->where('phpVersion', PHP_VERSION),
        );
});

it('downloads a real, freshly generated support bundle marked for cleanup after sending', function () {
    $response = $this->actingAs($this->admin)->get('/settings/advanced/support-bundle');
    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('zip')
        ->and($response->headers->get('content-disposition'))->toContain('attachment');

    // SettingsController::downloadSupportBundle() calls
    // ->deleteFileAfterSend(true) — Symfony's BinaryFileResponse only
    // actually unlinks the file once send() streams it to a real client
    // connection, which Laravel's HTTP testing client never invokes (it
    // inspects the Response object directly rather than emitting it), so
    // asserting the file is gone here would test the test harness, not
    // this application. Asserting the flag itself is set is the reliable,
    // meaningful check that cleanup WILL happen on a real request.
    $baseResponse = $response->baseResponse;
    expect($baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    expect($baseResponse->shouldDeleteFileAfterSend())->toBeTrue();
});
