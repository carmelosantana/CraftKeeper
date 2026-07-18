<?php

use App\Models\McpAuditEvent;
use App\Models\McpGrant;
use App\Models\Operation;
use App\Models\PluginInstallation;
use App\Models\User;
use App\Operations\InputRedactor;
use App\Operations\OperationActorType;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Support\ApiScope;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
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

/*
|--------------------------------------------------------------------------
| Step 1 (the brief's verbatim scenario, adapted): a config-propose grant
| can CREATE a proposal, but there is no approve_operation tool to call.
|--------------------------------------------------------------------------
|
| The brief's literal snippet passes `expected_sha256 = str_repeat('a',
| 64)` as a placeholder. App\Config\ConfigChangeService::propose() (Task
| 8) performs a real hash_equals() optimistic-concurrency check against
| the file's ACTUAL current content — a mismatched hash throws
| App\Config\Exceptions\ConfigConflict by design, and no legitimate
| placeholder could bypass that without weakening a real security
| boundary this task must not touch. This test writes a real fixture file
| and uses its REAL sha256, matching the exact pattern Task 17's own
| ConfigApiTest.php already established for the identical situation.
*/

it('lets a config-propose grant create but not approve a proposal', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $grant = McpGrant::factory()->withScopes(['config:read', 'config:propose'])->create();

    $proposal = $this->callMcpTool($grant, 'propose_config_change', [
        'path' => 'server.properties',
        'expected_sha256' => hash('sha256', $contents),
        'changes' => [['path' => 'allow-flight', 'value' => true]],
    ]);

    expect($proposal['status'])->toBe('proposed');

    $this->callMcpTool($grant, 'approve_operation', ['id' => $proposal['id']])
        ->assertMcpToolNotFound();
});

it('never registers an approve_operation tool anywhere on the server, regardless of grant', function () {
    $grant = McpGrant::factory()->withScopes(ApiScope::values())->create();

    $this->callMcpTool($grant, 'approve_operation')->assertMcpToolNotFound();
    $this->callMcpTool($grant, 'approve')->assertMcpToolNotFound();
    $this->callMcpTool($grant, 'execute_operation')->assertMcpToolNotFound();
    $this->callMcpTool(null, 'approve_operation')->assertMcpToolNotFound();
});

it('has no MCP tool whose class reaches OperationService::approve()', function () {
    $reflection = new ReflectionClass(OperationService::class);
    $approve = $reflection->getMethod('approve');
    $parameters = $approve->getParameters();

    // approve() only ever accepts a real, authenticated App\Models\User —
    // never App\Operations\OperationAuthor — so no MCP-authored call
    // (which only ever constructs OperationAuthor::mcp()) can satisfy
    // this signature, structurally, regardless of what any tool tries to
    // pass.
    expect($parameters[1]->getType()?->__toString())->toBe(User::class);

    foreach (glob(app_path('Mcp/Tools/*.php')) as $file) {
        expect(file_get_contents($file))->not->toContain('->approve(');
    }
});

/*
|--------------------------------------------------------------------------
| Every tool/resource requires its own scope — missing scope is denied.
|--------------------------------------------------------------------------
*/

it('denies propose_config_change without the config:propose scope', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $this->callMcpTool($grant, 'propose_config_change', [
        'path' => 'server.properties',
        'expected_sha256' => hash('sha256', $contents),
        'changes' => [['path' => 'motd', 'value' => 'hi']],
    ])->assertDenied('config:propose');

    expect(Operation::query()->count())->toBe(0);
});

it('denies propose_plugin_operation without the plugins:manage scope', function () {
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
    $grant = McpGrant::factory()->withScopes(['plugins:read'])->create();

    $this->callMcpTool($grant, 'propose_plugin_operation', [
        'filename' => 'Foo.jar',
        'operation' => 'disable',
    ])->assertDenied('plugins:manage');

    expect(Operation::query()->count())->toBe(0);
});

it('denies run_safe_rcon without the rcon:safe scope', function () {
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $this->callMcpTool($grant, 'run_safe_rcon', ['command' => 'list'])
        ->assertDenied('rcon:safe');

    expect(Operation::query()->count())->toBe(0);
});

it('run_safe_rcon refuses an Elevated command even with rcon:safe — it is never proposed', function () {
    $grant = McpGrant::factory()->withScopes(['rcon:safe'])->create();

    $result = $this->callMcpTool($grant, 'run_safe_rcon', ['command' => 'op Notch']);

    expect($result->isError())->toBeTrue()
        ->and($result->message())->toContain('not on');
    expect(Operation::query()->count())->toBe(0);
});

it('proposes a Safe command with rcon:safe, never executing it', function () {
    $grant = McpGrant::factory()->withScopes(['rcon:safe'])->create();

    $result = $this->callMcpTool($grant, 'run_safe_rcon', ['command' => 'list']);

    expect($result['status'])->toBe('proposed');
    $operation = Operation::query()->findOrFail($result['id']);
    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->author_type)->toBe(OperationActorType::Mcp);
});

it('denies each read-only resource without its own read scope', function () {
    $noScopeGrant = McpGrant::factory()->withScopes([])->create();

    $this->readMcpResource($noScopeGrant, 'craftkeeper://server/status')->assertDenied('server:read');
    $this->readMcpResource($noScopeGrant, 'craftkeeper://config/files')->assertDenied('config:read');
    $this->readMcpResource($noScopeGrant, 'craftkeeper://plugins')->assertDenied('plugins:read');
    $this->readMcpResource($noScopeGrant, 'craftkeeper://activity')->assertDenied('activity:read');
});

it('config:read never implies config:propose — a read-only grant cannot propose', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $this->readMcpResource($grant, 'craftkeeper://config/files')->assertOk();

    $this->callMcpTool($grant, 'propose_config_change', [
        'path' => 'server.properties',
        'expected_sha256' => hash('sha256', $contents),
        'changes' => [['path' => 'motd', 'value' => 'bye']],
    ])->assertDenied('config:propose');
});

/*
|--------------------------------------------------------------------------
| A revoked or expired grant fails EVERY call, regardless of scope.
|--------------------------------------------------------------------------
*/

it('a revoked grant fails every call', function () {
    $grant = McpGrant::factory()->withScopes(ApiScope::values())->revoked()->create();

    $this->readMcpResource($grant, 'craftkeeper://server/status')->assertDenied('revoked');
    $this->callMcpTool($grant, 'run_safe_rcon', ['command' => 'list'])->assertDenied('revoked');
    expect(Operation::query()->count())->toBe(0);
});

it('an expired grant fails every call', function () {
    $grant = McpGrant::factory()->withScopes(ApiScope::values())->expired()->create();

    $this->readMcpResource($grant, 'craftkeeper://server/status')->assertDenied('expired');
    $this->callMcpTool($grant, 'run_safe_rcon', ['command' => 'list'])->assertDenied('expired');
    expect(Operation::query()->count())->toBe(0);
});

it('a request with no grant at all (anonymous) is denied, never silently allowed', function () {
    $this->readMcpResource(null, 'craftkeeper://server/status')->assertDenied();
});

/*
|--------------------------------------------------------------------------
| MCP-created proposals land in the ordinary human approval queue as
| Proposed, and stay there until a human approves via the web UI.
|--------------------------------------------------------------------------
*/

it('an MCP-proposed config change appears in the web activity/approval queue as Proposed', function () {
    $contents = "motd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $grant = McpGrant::factory()->withScopes(['config:propose'])->create();

    $result = $this->callMcpTool($grant, 'propose_config_change', [
        'path' => 'server.properties',
        'expected_sha256' => hash('sha256', $contents),
        'changes' => [['path' => 'motd', 'value' => 'bye']],
    ]);

    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get('/activity')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Activity')
            ->has('items', 1)
            ->where('items.0.id', 'operation:'.$result['id'])
            ->where('items.0.status', 'proposed')
            ->where('items.0.actor.type', 'mcp'));

    $operation = Operation::query()->findOrFail($result['id']);
    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->author_type)->toBe(OperationActorType::Mcp)
        ->and($operation->author_id)->toBe($grant->oauth_client_id);

    // Still Proposed after being LISTED on the human review page — no MCP
    // path advanced it, and nothing here calls any approval action.
    expect($operation->fresh()->status)->toBe(OperationStatus::Proposed);
});

/*
|--------------------------------------------------------------------------
| Full audit: every call records client, subject, scope decision,
| correlation id, REDACTED arguments, duration, and outcome.
|--------------------------------------------------------------------------
*/

it('audits an allowed call with the grant, subject, scope, and outcome', function () {
    $grant = McpGrant::factory()->withScopes(['rcon:safe'])->create();

    $result = $this->callMcpTool($grant, 'run_safe_rcon', ['command' => 'list']);

    $event = McpAuditEvent::query()->latest()->first();
    expect($event)->not->toBeNull()
        ->and($event->mcp_grant_id)->toBe($grant->id)
        ->and($event->subject_type)->toBe('tool')
        ->and($event->subject_name)->toBe('run_safe_rcon')
        ->and($event->scope)->toBe('rcon:safe')
        ->and($event->outcome)->toBe('allowed')
        ->and($event->correlation_id)->not->toBeEmpty()
        ->and($event->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($event->arguments)->toBe(['command' => 'list']);
});

it('audits a denied call with the denial reason, never allowing it through', function () {
    $grant = McpGrant::factory()->withScopes([])->create();

    $this->readMcpResource($grant, 'craftkeeper://server/status');

    $event = McpAuditEvent::query()->latest()->first();
    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe('denied')
        ->and($event->denial_reason)->toContain('server:read');
});

it('redacts a secret-shaped argument before it is ever written to the audit log', function () {
    $grant = McpGrant::factory()->withScopes(['rcon:safe'])->create();

    // "password" is not an actual argument key any of these tools accept,
    // but App\Operations\InputRedactor redacts by KEY NAME regardless of
    // which tool sent it — this proves the redaction pass genuinely runs
    // on every audited argument set, not just a hand-picked known field.
    $this->callMcpTool($grant, 'run_safe_rcon', ['command' => 'list', 'password' => 'super-secret-value']);

    $event = McpAuditEvent::query()->latest()->first();
    expect($event->arguments['password'])->toBe(InputRedactor::MASK);
    expect(json_encode($event->arguments))->not->toContain('super-secret-value');
});

it('audits a validation failure with a real, specific message — not a generic error', function () {
    $grant = McpGrant::factory()->withScopes(['rcon:safe'])->create();

    // Missing the required "command" argument entirely.
    $result = $this->callMcpTool($grant, 'run_safe_rcon', []);

    expect($result->isError())->toBeTrue()
        ->and($result->message())->toContain('command');

    $event = McpAuditEvent::query()->latest()->first();
    expect($event->outcome)->toBe('error')
        ->and($event->denial_reason)->toContain('command');
});

it('never audits a raw secret value proposed through propose_config_change', function () {
    $contents = "rcon.password=actual-secret-value\nmotd=hi\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);
    $grant = McpGrant::factory()->withScopes(['config:propose'])->create();

    $this->callMcpTool($grant, 'propose_config_change', [
        'path' => 'server.properties',
        'expected_sha256' => hash('sha256', $contents),
        'changes' => [['path' => 'rcon.password', 'value' => 'a-new-secret-value']],
    ]);

    $event = McpAuditEvent::query()->latest()->first();
    expect(json_encode($event->arguments))->not->toContain('a-new-secret-value');
});
