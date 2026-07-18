<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

it('requires authentication for the integrations api page', function () {
    $this->get('/integrations/api')->assertRedirect('/login');
});

it('redirects /integrations to the api page', function () {
    $this->actingAs($this->admin)
        ->get('/integrations')
        ->assertRedirect('/integrations/api');
});

it('renders the available scopes and any existing tokens', function () {
    $this->admin->createToken('reader', ['server:read']);

    $this->actingAs($this->admin)
        ->get('/integrations/api')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('integrations/Api')
            ->has('availableScopes', 9)
            ->has('tokens', 1)
            ->where('tokens.0.name', 'reader')
            ->where('tokens.0.abilities', ['server:read'])
            ->missing('newToken')
        );
});

it('creates a scoped token and shows the plaintext value exactly once', function () {
    $response = $this->actingAs($this->admin)
        ->post('/integrations/api/tokens', [
            'name' => 'Backup automation',
            'scopes' => ['config:read', 'plugins:read'],
        ])
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('integrations/Api')
        ->where('newToken.name', 'Backup automation')
        ->has('newToken.plainText')
    );

    $plainText = $response->viewData('page')['props']['newToken']['plainText'];
    expect($plainText)->toBeString();

    // The stored row never carries the plaintext value — only Sanctum's
    // sha256 hash (App\Support\ApiScope-backed abilities are stored as
    // plain strings, which is fine; the *token* itself is hashed).
    $stored = PersonalAccessToken::query()->where('name', 'Backup automation')->firstOrFail();
    [, $rawToken] = explode('|', $plainText, 2);
    expect($stored->token)->toBe(hash('sha256', $rawToken))
        ->and($stored->abilities)->toBe(['config:read', 'plugins:read']);

    // A SECOND page load (no fresh token created) never carries newToken.
    $this->actingAs($this->admin)
        ->get('/integrations/api')
        ->assertInertia(fn (Assert $page) => $page->missing('newToken'));
});

it('rejects an unknown scope value', function () {
    $this->actingAs($this->admin)
        ->post('/integrations/api/tokens', [
            'name' => 'Bad token',
            'scopes' => ['not-a-real-scope'],
        ])
        ->assertSessionHasErrors('scopes.0');

    expect(PersonalAccessToken::query()->where('name', 'Bad token')->exists())->toBeFalse();
});

it('revokes a token belonging to the current user', function () {
    $token = $this->admin->createToken('reader', ['server:read']);

    $this->actingAs($this->admin)
        ->delete("/integrations/api/tokens/{$token->accessToken->id}")
        ->assertRedirect('/integrations/api');

    expect(PersonalAccessToken::query()->find($token->accessToken->id))->toBeNull();
});

it('refuses to revoke another user\'s token', function () {
    $other = User::factory()->create();
    $token = $other->createToken('someone-elses', ['server:read']);

    $this->actingAs($this->admin)
        ->delete("/integrations/api/tokens/{$token->accessToken->id}")
        ->assertNotFound();

    expect(PersonalAccessToken::query()->find($token->accessToken->id))->not->toBeNull();
});

it('serves the openapi.yaml reference to an authenticated user', function () {
    $response = $this->actingAs($this->admin)
        ->get('/openapi.yaml')
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/yaml');
});
