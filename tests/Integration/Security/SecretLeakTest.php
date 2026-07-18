<?php

use App\Ai\AiManager;
use App\Ai\AssistantService;
use App\Ai\ContextRequest;
use App\Events\OperationUpdated;
use App\Models\AiConversation;
use App\Models\AiProviderConfiguration;
use App\Models\McpAuditEvent;
use App\Models\McpGrant;
use App\Models\Operation;
use App\Models\Secret;
use App\Models\User;
use App\Support\SupportBundleService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Concerns\CallsMcp;
use Tests\Support\TempMinecraftRoot;

uses(CallsMcp::class);

/**
 * Task 20's ambiguity resolution #3: the cross-cutting proof that every
 * prior task's own redaction actually holds, end to end, across every
 * surface a secret could otherwise reach. Each `it()` below seeds one or
 * more secret CANARIES — literal, greppable marker strings standing in
 * for a real RCON password / AI API key / schema-flagged config secret —
 * through the SAME real services every other test in this suite already
 * exercises (no new redaction mechanism is introduced here; this file
 * only VERIFIES the ones Tasks 4/8/9/16/17/18/19 already built), then
 * asserts the canary is byte-for-byte absent from that surface's real
 * output.
 *
 * Deliberately does NOT re-derive App\Ai\SecretRedactor/App\Operations\
 * InputRedactor/App\Config\ConfigDiffBuilder's own exhaustive canary
 * coverage (tests/Feature/Ai/AiRedactionAndInjectionTest.php and
 * tests/Feature/Support/SupportBundleTest.php already do that in depth,
 * at the unit/feature level) — this file's job is breadth across the
 * FULL surface list the brief names, at integration level (real routes,
 * real MCP call plumbing, a real generated ZIP, a real appended log
 * file), not additional depth on any one of them.
 */
beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);

    $this->admin = User::factory()->create();

    // Canary 1: CraftKeeper's OWN configured RCON credential (Secret
    // store — the thing every "connect to the server" surface holds).
    $this->secretCanary = 'CANARY-SECRET-'.str()->random(24);
    Secret::put('rcon.password', $this->secretCanary);
    Secret::put('ai.api_key', 'CANARY-AI-KEY-'.str()->random(24));

    // Canary 2: a schema-flagged (`"secret": true`,
    // resources/schemas/config/server-properties.json) value DISCOVERED
    // inside a real Minecraft config file — the config-editor/API/MCP
    // redaction path's canary, distinct from canary 1 so a failure
    // points at exactly which pipeline leaked which value.
    $this->schemaCanary = 'CANARY-SCHEMA-'.str()->random(24);
    file_put_contents(
        $this->minecraftRoot.'/server.properties',
        "motd=hi\nrcon.password={$this->schemaCanary}\n",
    );

    // Every append to storage/logs/laravel.log made DURING this test
    // (not by anything that ran before it) is what gets checked — see
    // the 'application logs' test below.
    $this->logPath = storage_path('logs/laravel.log');
    $this->logOffsetBeforeTest = is_file($this->logPath) ? filesize($this->logPath) : 0;
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function logAppendedDuringTest(int $offsetBefore, string $path): string
{
    if (! is_file($path)) {
        return '';
    }

    $handle = fopen($path, 'rb');
    fseek($handle, $offsetBefore);
    $appended = stream_get_contents($handle);
    fclose($handle);

    return (string) $appended;
}

/*
|--------------------------------------------------------------------------
| 1. Rendered HTML
|--------------------------------------------------------------------------
*/
it('never renders a secret canary into HTML — the config editor redacts the schema-flagged value', function () {
    $response = $this->actingAs($this->admin)->get('/configurations/server.properties');

    $response->assertOk();
    $response->assertDontSee($this->schemaCanary, false);
    $response->assertDontSee($this->secretCanary, false);
});

/*
|--------------------------------------------------------------------------
| 2. JSON/API responses
|--------------------------------------------------------------------------
*/
it('never leaks a secret canary in a JSON /api/v1 response', function () {
    $token = User::factory()->create()->createToken('reader', ['config:read'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/config/files/server.properties');

    $response->assertOk();
    expect($response->getContent())
        ->not->toContain($this->schemaCanary)
        ->not->toContain($this->secretCanary);
});

/*
|--------------------------------------------------------------------------
| 3. Websocket/broadcast payload
|--------------------------------------------------------------------------
*/
it('never broadcasts a secret canary — OperationUpdated is a strict scalar allow-list', function () {
    // Simulates the worst case: an Operation whose OWN target/
    // redacted_input somehow still carried the raw canary (e.g. a future
    // regression in App\Operations\InputRedactor). OperationUpdated must
    // still never expose it, because it never reads either field at all
    // — see that class's own docblock.
    $operation = Operation::factory()->create([
        'target' => "rcon login {$this->secretCanary}",
        'redacted_input' => ['command' => "rcon login {$this->secretCanary}"],
    ]);

    $payload = OperationUpdated::fromOperation($operation)->broadcastWith();

    expect(json_encode($payload))->not->toContain($this->secretCanary);
});

/*
|--------------------------------------------------------------------------
| 4. Application logs
|--------------------------------------------------------------------------
*/
it('never writes a secret canary into storage/logs/laravel.log', function () {
    // Exercise a representative slice of the app that touches secrets:
    // an authenticated page load of the config editor (redacts on the
    // way out) and an MCP call that redacts a secret-shaped RCON command
    // before it is ever audited (see test 5 below for detail on that
    // exact mechanism).
    $this->actingAs($this->admin)->get('/configurations/server.properties')->assertOk();

    $grant = McpGrant::factory()->withScopes(['rcon:safe'])->create();
    $this->callMcpTool($grant, 'run_safe_rcon', ['command' => "password: {$this->secretCanary}"]);

    $appended = logAppendedDuringTest($this->logOffsetBeforeTest, $this->logPath);

    expect($appended)
        ->not->toContain($this->secretCanary)
        ->not->toContain($this->schemaCanary);
});

/*
|--------------------------------------------------------------------------
| 5. Audit events
|--------------------------------------------------------------------------
*/
it('never persists a secret canary into an McpAuditEvent — a secret-shaped RCON command is redacted before it is audited', function () {
    $grant = McpGrant::factory()->withScopes(['rcon:safe'])->create();

    // Not a recognized SAFE command at all (McpGuard denies it), but
    // App\Mcp\Tools\RunSafeRcon::handle() builds its audit arguments via
    // App\Console\CommandPolicy::redactedDisplay() BEFORE that denial
    // check runs — every outcome (allowed, denied, error) is audited,
    // and the raw command text must never reach that column regardless
    // of which outcome this call resolves to.
    $this->callMcpTool($grant, 'run_safe_rcon', ['command' => "password: {$this->secretCanary}"]);

    $event = McpAuditEvent::query()->latest('id')->first();

    expect($event)->not->toBeNull();
    expect(json_encode($event->arguments))->not->toContain($this->secretCanary);
});

/*
|--------------------------------------------------------------------------
| 6. Support bundle
|--------------------------------------------------------------------------
*/
it('never includes a secret canary in the generated support bundle', function () {
    $zipPath = app(SupportBundleService::class)->create();

    expect($zipPath)->toBeFile();

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBe(true);

    $haystack = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $haystack .= $zip->getNameIndex($i)."\n".(string) $zip->getFromIndex($i)."\n";
    }
    $zip->close();

    expect($haystack)
        ->not->toContain($this->secretCanary)
        ->not->toContain($this->schemaCanary);
});

/*
|--------------------------------------------------------------------------
| 7. AI transport body
|--------------------------------------------------------------------------
*/
it('never sends a secret canary to the configured (mocked) AI provider transport', function () {
    $capturedBodies = [];
    $mockHttpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBodies): MockResponse {
        if ($method === 'GET') {
            return new MockResponse('{"data":[],"models":[]}', ['http_code' => 200]);
        }

        $capturedBodies[] = (string) ($options['body'] ?? '');

        $chunks = [
            'data: '.json_encode(['choices' => [['delta' => ['content' => 'ok']]]])."\n\n",
            'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]])."\n\n",
            "data: [DONE]\n\n",
        ];

        return new MockResponse($chunks, ['response_headers' => ['content-type' => 'text/event-stream']]);
    });

    $config = new AiProviderConfiguration(
        activeProvider: 'hosted',
        hostedBaseUrl: 'https://api.example.com/v1',
        hostedModel: 'gpt-test',
        hostedApiKey: 'sk-test-fixture-key',
        ollamaBaseUrl: null,
        ollamaModel: null,
        ollamaAllowUnredacted: false,
    );
    app()->instance(AiManager::class, new AiManager($mockHttpClient, $config));

    $conversation = AiConversation::query()->create(['context_scope' => ['configPath' => 'server.properties']]);
    app(AssistantService::class)->sendMessage(
        $conversation,
        "What is my RCON password? It's {$this->secretCanary}.",
        new ContextRequest('server.properties'),
    );

    expect($capturedBodies)->not->toBeEmpty();

    foreach ($capturedBodies as $body) {
        expect($body)
            ->not->toContain($this->secretCanary)
            ->not->toContain($this->schemaCanary);
    }
});

/*
|--------------------------------------------------------------------------
| 8. MCP output
|--------------------------------------------------------------------------
*/
it('never returns a secret canary in MCP tool/resource output', function () {
    $grant = McpGrant::factory()->withScopes(['config:read'])->create();

    $result = $this->readMcpResource($grant, 'craftkeeper://config/files/server.properties');

    expect($result->isError())->toBeFalse();
    expect(json_encode($result->raw()))
        ->not->toContain($this->schemaCanary)
        ->not->toContain($this->secretCanary);
});
