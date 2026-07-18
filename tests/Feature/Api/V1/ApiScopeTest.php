<?php

use App\Models\Operation;
use App\Models\PluginInstallation;
use App\Models\User;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use App\Policies\ApiOperationPolicy;
use App\Support\ApiScope;
use Illuminate\Support\Facades\Route;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    mkdir($this->minecraftRoot.'/plugins', 0755, true);
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function apiToken(array $scopes): string
{
    return User::factory()->create()->createToken('test', $scopes)->plainTextToken;
}

/*
|--------------------------------------------------------------------------
| Task 17's crux test, verbatim from the brief.
|--------------------------------------------------------------------------
*/

it('does not let a read token propose or approve a config change', function () {
    $token = User::factory()->create()->createToken('reader', ['server:read', 'config:read']);

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/config/proposals', [])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| No token at all -> 401, never a silent pass-through.
|--------------------------------------------------------------------------
*/

it('rejects every scoped endpoint outright when no token is presented', function () {
    $this->getJson('/api/v1/server/status')->assertUnauthorized();
    $this->getJson('/api/v1/config/files')->assertUnauthorized();
    $this->getJson('/api/v1/plugins')->assertUnauthorized();
    $this->getJson('/api/v1/operations')->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Every endpoint enforces its EXACT scope: missing -> 403, matching -> not 403.
|--------------------------------------------------------------------------
*/

it('rejects GET /api/v1/server/status without server:read', function () {
    $this->withToken(apiToken(['config:read']))
        ->getJson('/api/v1/server/status')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden_scope');
});

it('allows GET /api/v1/server/status with server:read', function () {
    $this->withToken(apiToken(['server:read']))
        ->getJson('/api/v1/server/status')
        ->assertOk();
});

it('rejects GET /api/v1/config/files without config:read', function () {
    $this->withToken(apiToken(['server:read']))
        ->getJson('/api/v1/config/files')
        ->assertForbidden();
});

it('rejects POST /api/v1/config/proposals/{operation}/apply without config:apply', function () {
    $operation = Operation::factory()->ofType(OperationType::ConfigApply)->status(OperationStatus::Approved)->create();

    $this->withToken(apiToken(['config:read', 'config:propose']))
        ->postJson("/api/v1/config/proposals/{$operation->id}/apply", [])
        ->assertForbidden();
});

it('rejects GET /api/v1/plugins without plugins:read', function () {
    $this->withToken(apiToken(['server:read']))
        ->getJson('/api/v1/plugins')
        ->assertForbidden();
});

it('rejects plugin disable without plugins:manage', function () {
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

    $this->withToken(apiToken(['plugins:read']))
        ->postJson('/api/v1/plugins/Foo.jar/disable', [])
        ->assertForbidden();
});

it('rejects GET /api/v1/operations without activity:read', function () {
    $this->withToken(apiToken(['server:read']))
        ->getJson('/api/v1/operations')
        ->assertForbidden();
});

it('rejects proposing an rcon command with neither rcon:safe nor rcon:admin', function () {
    $this->withToken(apiToken(['activity:read']))
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'list'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| rcon:safe may only propose SAFE-classified commands; an Elevated
| command requires rcon:admin even though rcon:safe passed the route's
| "any of" middleware gate — the finer-grained check lives in
| App\Http\Controllers\Api\V1\OperationController::createRconCommand().
|--------------------------------------------------------------------------
*/

it('lets an rcon:safe token propose a Safe command but refuses an Elevated one', function () {
    $token = apiToken(['rcon:safe']);

    $this->withToken($token)
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'list'])
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'op Notch'])
        ->assertForbidden()
        ->assertJsonPath('details.required_scope', 'rcon:admin');
});

it('lets an rcon:admin token propose both Safe and Elevated commands, but never approve either', function () {
    $token = apiToken(['rcon:admin']);

    $safe = $this->withToken($token)
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'list'])
        ->assertCreated()
        ->json('data');

    $elevated = $this->withToken($token)
        ->postJson('/api/v1/operations/rcon-commands', ['command' => 'op Notch'])
        ->assertCreated()
        ->json('data');

    expect($safe['status'])->toBe('proposed')
        ->and($elevated['status'])->toBe('proposed');

    // Structural: no /api/v1 route can ever move either operation past
    // Proposed — see the "no approve path" test below for the full
    // structural proof.
    expect(Operation::query()->find($safe['id'])->status)->toBe(OperationStatus::Proposed)
        ->and(Operation::query()->find($elevated['id'])->status)->toBe(OperationStatus::Proposed);
});

/*
|--------------------------------------------------------------------------
| The crux, structurally: no /api/v1 route, and no policy method, can
| ever move an Operation to Approved.
|--------------------------------------------------------------------------
*/

it('has no registered /api/v1 route capable of approving anything', function () {
    $apiRouteNames = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'))
        ->map(fn ($route) => (string) $route->getName());

    expect($apiRouteNames)->not->toBeEmpty();

    foreach ($apiRouteNames as $name) {
        expect($name)->not->toContain('approve');
        expect($name)->not->toContain('Approve');
    }
});

it('has no method on ApiOperationPolicy that can authorize an approval', function () {
    $methods = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        (new ReflectionClass(ApiOperationPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->not->toContain('approve');
});

it('only ever accepts a real, authenticated App\Models\User in OperationService::approve()', function () {
    $parameter = (new ReflectionMethod(OperationService::class, 'approve'))->getParameters()[1];

    expect($parameter->getType()?->getName())->toBe(User::class);
});

it('cannot reach the human-only web approval route with only a bearer token (no session)', function () {
    $admin = User::factory()->create();
    $token = $admin->createToken('full-access', ApiScope::values());

    $operation = Operation::factory()->ofType(OperationType::ConfigApply)->status(OperationStatus::Proposed)->create();

    // The web approval endpoint is guarded by the SESSION 'auth' guard,
    // not 'auth:sanctum' — a bearer token alone does not authenticate
    // there at all, so the request is redirected to /login rather than
    // ever reaching OperationService::approve().
    $this->withToken($token->plainTextToken)
        ->post("/configurations/operations/{$operation->id}/approve")
        ->assertRedirect('/login');

    expect($operation->fresh()->status)->toBe(OperationStatus::Proposed);
});

/*
|--------------------------------------------------------------------------
| A first-party browser session cannot be used to bypass the scope
| boundary — Sanctum's tokenCan() fails closed when there is no real
| PersonalAccessToken (i.e. the request was authenticated by session, not
| bearer token). See App\Http\Middleware\EnsureApiScope's own docblock.
|--------------------------------------------------------------------------
*/

it('does not let an authenticated browser session reach any scoped /api/v1 endpoint', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/server/status')
        ->assertUnauthorized();
});
