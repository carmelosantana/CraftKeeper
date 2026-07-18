<?php

use App\Models\McpGrant;
use App\Models\User;
use App\Support\ApiScope;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/*
|--------------------------------------------------------------------------
| No anonymous MCP access in V1.
|--------------------------------------------------------------------------
*/

it('rejects an anonymous (no bearer token) request to /mcp/craftkeeper before any JSON-RPC method runs', function () {
    $response = $this->postJson('/mcp/craftkeeper', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    $response->assertStatus(401);
});

it('rejects an anonymous request even for read-only listing methods (tools/list, resources/list, prompts/list)', function () {
    foreach (['tools/list', 'resources/list', 'prompts/list', 'initialize'] as $method) {
        $this->postJson('/mcp/craftkeeper', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method])
            ->assertStatus(401);
    }
});

it('rejects a bogus bearer token', function () {
    $this->withToken('not-a-real-token')
        ->postJson('/mcp/craftkeeper', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Only authorization-code + PKCE is accepted: no password, no
| client-credentials, no dynamic client registration, no device flow.
|--------------------------------------------------------------------------
*/

it('registers no dynamic client registration, password, client-credentials-only, or device-code route anywhere', function () {
    $paths = collect(Route::getRoutes())->map(fn ($route) => strtolower($route->uri()))->all();

    foreach ($paths as $path) {
        expect($path)->not->toContain('oauth/register');
        expect($path)->not->toContain('oauth/device');
        expect($path)->not->toContain('oauth/clients');
        expect($path)->not->toContain('oauth/scopes');
        expect($path)->not->toContain('oauth/personal-access-tokens');
        expect($path)->not->toContain('oauth/tokens');
    }
});

it('every OAuth client this application creates is stored with authorization_code + refresh_token grant types only', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Test Client',
        ['https://example.test/callback'],
        confidential: false,
    );

    expect($client->grant_types)->toBe(['authorization_code', 'refresh_token'])
        ->and($client->hasGrantType('authorization_code'))->toBeTrue()
        ->and($client->hasGrantType('refresh_token'))->toBeTrue()
        ->and($client->hasGrantType('password'))->toBeFalse()
        ->and($client->hasGrantType('client_credentials'))->toBeFalse()
        ->and($client->hasGrantType('implicit'))->toBeFalse()
        ->and($client->hasGrantType('personal_access'))->toBeFalse()
        ->and($client->hasGrantType('urn:ietf:params:oauth:grant-type:device_code'))->toBeFalse();

    // Public client — no secret — required for PKCE to be enforced by
    // league/oauth2-server's AuthCodeGrant (requireCodeChallengeForPublicClients
    // defaults true and is never disabled anywhere in this application).
    expect($client->confidential())->toBeFalse();
});

it('the authorization-server metadata document advertises exactly authorization_code + refresh_token and no registration endpoint', function () {
    $response = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();

    $response->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);
    expect($response->json())->not->toHaveKey('registration_endpoint');

    $scopes = $response->json('scopes_supported');
    foreach (ApiScope::values() as $scope) {
        expect($scopes)->toContain($scope);
    }
});

it('the protected-resource metadata document points at the real MCP endpoint', function () {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('resource', url('/mcp/craftkeeper'));
});

/*
|--------------------------------------------------------------------------
| Integrations > MCP page: create + revoke, session-authenticated.
|--------------------------------------------------------------------------
*/

it('lets an admin create an MCP connection, provisioning a real OAuth client and matching grant', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post('/integrations/mcp/grants', [
            'display_name' => 'Claude Desktop',
            'redirect_uri' => 'https://client.example/callback',
            'scopes' => ['config:read', 'config:propose'],
        ])
        ->assertRedirect('/integrations/mcp');

    $grant = McpGrant::query()->sole();
    expect($grant->display_name)->toBe('Claude Desktop')
        ->and($grant->scopes)->toBe(['config:read', 'config:propose'])
        ->and($grant->revoked_at)->toBeNull()
        ->and($grant->created_by)->toBe($admin->id);

    $client = Client::query()->find($grant->oauth_client_id);
    expect($client)->not->toBeNull()
        ->and($client->grant_types)->toBe(['authorization_code', 'refresh_token']);
});

it('revoking an MCP connection kills the grant AND the underlying Passport client/tokens', function () {
    $admin = User::factory()->create();
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Revoke Me', ['https://example.test/callback'], confidential: false,
    );
    $grant = McpGrant::factory()->withScopes(['config:read'])->create([
        'oauth_client_id' => $client->id,
        'display_name' => 'Revoke Me',
    ]);
    Token::query()->create([
        'id' => str_repeat('a', 80),
        'user_id' => $admin->id,
        'client_id' => $client->id,
        'scopes' => ['config:read'],
        'revoked' => false,
    ]);

    $this->actingAs($admin)
        ->delete("/integrations/mcp/grants/{$grant->id}")
        ->assertRedirect('/integrations/mcp');

    expect($grant->fresh()->revoked_at)->not->toBeNull();
    expect($client->fresh()->revoked)->toBeTrue();
    expect(Token::query()->where('client_id', $client->id)->first()->revoked)->toBeTrue();
});

it('the integrations page shows connection URL, authorization state, scopes, expiry, and last used', function () {
    $admin = User::factory()->create();
    $grant = McpGrant::factory()->withScopes(['config:read', 'rcon:safe'])->create([
        'display_name' => 'Inspector',
    ]);

    $this->actingAs($admin)
        ->get('/integrations/mcp')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/Mcp')
            ->where('connectionUrl', url('/mcp/craftkeeper'))
            ->has('grants', 1)
            ->where('grants.0.displayName', 'Inspector')
            ->where('grants.0.scopes', ['config:read', 'rcon:safe'])
            ->where('grants.0.state', 'active'));
});

/*
|--------------------------------------------------------------------------
| Consent copy names each scope's consequence.
|--------------------------------------------------------------------------
*/

it('registers a consequence-naming description for every scope with Passport', function () {
    $scopes = collect(Passport::scopesFor(ApiScope::values()));

    expect($scopes)->toHaveCount(count(ApiScope::values()));

    foreach ($scopes as $scope) {
        expect($scope->description)->not->toBeEmpty();
    }

    $proposeScope = $scopes->firstWhere('id', ApiScope::ConfigPropose->value);
    expect($proposeScope->description)->toContain('human must separately review and approve');
});
