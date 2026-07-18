<?php

use App\Models\McpGrant;
use App\Models\User;
use Tests\Concerns\CallsMcp;
use Tests\Support\TempMinecraftRoot;

uses(CallsMcp::class);

/**
 * Task 20's ambiguity resolution #4: integration-level proof that
 * filesystem containment (App\Filesystem\MinecraftPath::fromUserInput())
 * actually holds END TO END through the real, unmocked entry points that
 * accept a caller-supplied relative path — the web config editor, the
 * REST API, and an MCP resource — driven exactly the way a real
 * browser/API client/MCP client would, rather than re-testing
 * MinecraftPath in isolation the way tests/Unit/Filesystem/
 * MinecraftPathTest.php already does exhaustively. This file's job is
 * proving three INDEPENDENT real callers all inherit the identical
 * guarantee from that one shared choke point, not re-deriving it.
 *
 * Verified empirically (not assumed) that a literal ".." in the request
 * URI survives Laravel's routing unnormalized and really does reach
 * App\Http\Controllers\ConfigController::edit()'s `$path` parameter — a
 * `Illuminate\Routing\Events\RouteMatched` listener confirmed the
 * `configurations.edit` route (not routing-level 404) is what a
 * traversal request matches, with `abort(404)` firing from that
 * controller's own `catch (UnsafeMinecraftPath)` afterward. Both that
 * catch and an unmatched route produce the exact same
 * `NotFoundHttpException`/404 page, which is why this file also confirms
 * containment through at least one OTHER real entry point (the API,
 * which renders Task 17's distinct `{code, message, ...}` JSON error
 * shape rather than an HTML 404 page) for a signal that cannot be
 * confused with "route never matched" at all.
 *
 * Reuses tests/fixtures/minecraft's own pre-built symlink escape vectors
 * (`escape-link.yml`, `escape-dir/secret.txt`) — read-only throughout,
 * never modified by anything below.
 */
beforeEach(function () {
    $this->fixtureRoot = realpath(base_path('tests/fixtures/minecraft'));
    config(['craftkeeper.minecraft_root' => $this->fixtureRoot]);
    $this->admin = User::factory()->create();
});

/*
|--------------------------------------------------------------------------
| Positive control: a real, in-bounds file resolves fine through all
| three entry points — containment isn't just blanket-rejecting
| everything.
|--------------------------------------------------------------------------
*/
// Split into one test per entry point rather than combined into one:
// Laravel's test client does not cleanly support switching from a
// session-authenticated (`actingAs()`) request to a Sanctum
// `withToken()` request within the SAME test — session state from the
// first persists and the token request 401s, a testing-harness quirk
// unrelated to anything this task changed.
it('still serves a real, contained file through the web config editor', function () {
    $this->actingAs($this->admin)
        ->get('/configurations/server.properties')
        ->assertOk();
});

it('still serves a real, contained file through the REST API', function () {
    $token = User::factory()->create()->createToken('reader', ['config:read'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/config/files/server.properties')
        ->assertOk()
        ->assertJsonPath('data.path', 'server.properties');
});

it('still serves a real, contained file through an MCP resource', function () {
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $result = $this->readMcpResource($grant, 'craftkeeper://config/files/server.properties');

    expect($result->isError())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Path traversal
|--------------------------------------------------------------------------
*/
it('refuses a path-traversal attempt through the web config editor', function () {
    $this->actingAs($this->admin)
        ->get('/configurations/../../../etc/passwd')
        ->assertNotFound();
});

it('refuses a path-traversal attempt through the REST API with the shared JSON error shape', function () {
    $token = User::factory()->create()->createToken('reader', ['config:read'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/config/files/'.rawurlencode('../../../etc/passwd'))
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('refuses a path-traversal attempt through an MCP resource', function () {
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $result = $this->readMcpResource(
        $grant,
        'craftkeeper://config/files/'.rawurlencode('../../../etc/passwd'),
    );

    expect($result->isError())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Symlink escape
|--------------------------------------------------------------------------
*/
it('refuses a symlink that escapes the Minecraft root through the web config editor', function () {
    $this->actingAs($this->admin)
        ->get('/configurations/escape-link.yml')
        ->assertNotFound();
});

it('refuses a directory symlink that escapes the Minecraft root before descending into it', function () {
    $this->actingAs($this->admin)
        ->get('/configurations/escape-dir/secret.txt')
        ->assertNotFound();
});

it('refuses a symlink escape through the REST API', function () {
    $token = User::factory()->create()->createToken('reader', ['config:read'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/config/files/escape-link.yml')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('refuses a symlink escape through an MCP resource', function () {
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $result = $this->readMcpResource($grant, 'craftkeeper://config/files/escape-link.yml');

    expect($result->isError())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Non-regular file (a real FIFO, not a symlink/directory) — needs its own
| disposable root since the git-tracked fixture directory can't carry a
| FIFO special file.
|--------------------------------------------------------------------------
*/
it('refuses a non-regular file (a FIFO) through the web config editor', function () {
    $root = TempMinecraftRoot::create();
    exec('mkfifo '.escapeshellarg($root.'/a.fifo'));
    config(['craftkeeper.minecraft_root' => $root]);

    try {
        $this->actingAs($this->admin)
            ->get('/configurations/a.fifo')
            ->assertNotFound();
    } finally {
        TempMinecraftRoot::destroy($root);
    }
});
