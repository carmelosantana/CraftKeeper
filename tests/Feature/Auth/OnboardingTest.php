<?php

use App\Models\Secret;
use App\Models\Setting;
use App\Models\User;
use App\Support\InstallationState;
use Illuminate\Support\Facades\DB;

it('allows creation of exactly one administrator', function () {
    $this->post('/onboarding/admin', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'a-long-unique-passphrase',
        'password_confirmation' => 'a-long-unique-passphrase',
    ])->assertRedirect('/onboarding/server');

    $this->post('/onboarding/admin', [
        'name' => 'Second',
        'email' => 'second@example.com',
        'password' => 'another-long-passphrase',
        'password_confirmation' => 'another-long-passphrase',
    ])->assertNotFound();
});

it('reports installation state from the presence of a user, not a flag', function () {
    expect(InstallationState::isInstalled())->toBeFalse();

    User::factory()->create();

    expect(InstallationState::isInstalled())->toBeTrue();
});

it('signs the newly created admin in immediately, with no email verification required', function () {
    $this->post('/onboarding/admin', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'a-long-unique-passphrase',
        'password_confirmation' => 'a-long-unique-passphrase',
    ]);

    $this->assertAuthenticated();

    $admin = User::sole();
    expect($admin->email)->toBe('admin@example.com')
        ->and($admin->email_verified_at)->not->toBeNull();
});

it('rejects invalid admin details without creating a user', function () {
    $this->post('/onboarding/admin', [
        'name' => 'Admin',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'does-not-match',
    ])->assertSessionHasErrors(['email', 'password']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('serves the onboarding welcome screen before install and 404s it after', function () {
    $this->get('/onboarding')->assertOk();

    $this->post('/onboarding/admin', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'a-long-unique-passphrase',
        'password_confirmation' => 'a-long-unique-passphrase',
    ]);

    $this->get('/onboarding')->assertNotFound();
});

it('requires authentication for onboarding steps after admin creation', function () {
    $this->get('/onboarding/server')->assertRedirect('/login');
    $this->get('/onboarding/rcon')->assertRedirect('/login');
    $this->get('/onboarding/ai')->assertRedirect('/login');
    $this->get('/onboarding/analytics')->assertRedirect('/login');
    $this->get('/onboarding/complete')->assertRedirect('/login');
});

it('walks the mocked onboarding steps end to end and every optional step is skippable', function () {
    $this->post('/onboarding/admin', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'a-long-unique-passphrase',
        'password_confirmation' => 'a-long-unique-passphrase',
    ])->assertRedirect('/onboarding/server');

    $this->post('/onboarding/server', [
        'minecraft_path' => '/srv/minecraft',
    ])->assertRedirect('/onboarding/rcon');

    expect(Setting::get('minecraft.server_path'))->toBe('/srv/minecraft');

    $this->post('/onboarding/rcon', [
        'rcon_host' => '127.0.0.1',
        'rcon_port' => 25575,
        'rcon_password' => 'a-strong-rcon-password',
    ])->assertRedirect('/onboarding/ai');

    // Optional steps: skipping means simply not posting anything and
    // moving straight to the next step's URL, which must work with no
    // prior data for that step.
    $this->get('/onboarding/ai')->assertOk();
    $this->get('/onboarding/analytics')->assertOk();

    $this->post('/onboarding/analytics', [])
        ->assertRedirect('/onboarding/complete');

    $this->get('/onboarding/complete')->assertOk();
});

it('never returns the rcon or ai secret value in any response', function () {
    $this->post('/onboarding/admin', [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'a-long-unique-passphrase',
        'password_confirmation' => 'a-long-unique-passphrase',
    ]);

    $rconPassword = 'super-secret-rcon-password';
    $aiApiKey = 'sk-super-secret-ai-key';

    $rconResponse = $this->post('/onboarding/rcon', [
        'rcon_host' => '127.0.0.1',
        'rcon_port' => 25575,
        'rcon_password' => $rconPassword,
    ]);
    $rconResponse->assertRedirect('/onboarding/ai');
    expect($rconResponse->getContent())->not->toContain($rconPassword);

    $aiResponse = $this->post('/onboarding/ai', [
        'ai_provider' => 'openai',
        'ai_api_key' => $aiApiKey,
    ]);
    $aiResponse->assertRedirect('/onboarding/analytics');
    expect($aiResponse->getContent())->not->toContain($aiApiKey);

    // Revisiting the steps must not echo the secrets back either — only a
    // "configured" boolean.
    $rconPage = $this->get('/onboarding/rcon');
    $rconPage->assertOk();
    expect($rconPage->getContent())->not->toContain($rconPassword)
        ->and($rconPage->getContent())->not->toContain('rcon_password');

    $aiPage = $this->get('/onboarding/ai');
    $aiPage->assertOk();
    expect($aiPage->getContent())->not->toContain($aiApiKey)
        ->and($aiPage->getContent())->not->toContain('ai_api_key');

    // And the database column itself must not hold the plaintext either
    // (Secret::value uses Laravel's `encrypted` cast).
    $rconSecretRow = DB::table('secrets')->where('key', 'rcon.password')->first();
    expect($rconSecretRow->value)->not->toContain($rconPassword);

    $aiSecretRow = DB::table('secrets')->where('key', 'ai.api_key')->first();
    expect($aiSecretRow->value)->not->toContain($aiApiKey);

    // But the decrypted value round-trips correctly through the model.
    expect(Secret::get('rcon.password'))->toBe($rconPassword)
        ->and(Secret::get('ai.api_key'))->toBe($aiApiKey);
});

it('never serializes a Secret value even if the model is dumped directly', function () {
    $secret = Secret::put('rcon.password', 'super-secret-rcon-password');

    expect($secret->toArray())->not->toHaveKey('value')
        ->and($secret->fresh()->toArray())->not->toHaveKey('value')
        ->and(json_encode($secret))->not->toContain('super-secret-rcon-password');
});
