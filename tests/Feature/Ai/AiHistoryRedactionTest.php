<?php

use App\Ai\AiManager;
use App\Ai\AssistantService;
use App\Ai\ContextRequest;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiProviderConfiguration;
use App\Models\Secret;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\TempMinecraftRoot;

/*
|--------------------------------------------------------------------------
| Task 16 fix pass: history replay must never carry a raw secret to a
| hosted provider
|--------------------------------------------------------------------------
|
| The scenario this file exists to close: an operator runs a turn with
| `ai.ollama.allow_unredacted` ON, a real secret value gets echoed into a
| STORED AiMessage (simulated here directly, since that's the smallest
| reproduction of "a raw secret is already sitting in history" regardless
| of how it got there). The operator later switches `ai.provider` to a
| HOSTED provider and keeps talking in the SAME conversation.
| App\Ai\AssistantService::sendMessage() builds `$history` straight from
| every prior AiMessage's raw `content` (see its own docblock) and, before
| this fix, handed that history to App\Ai\Providers\AbstractAiProvider::
| stream() untouched — so the secret embedded in that old turn would be
| serialized into the hosted /chat/completions request body. That is the
| exact violation of "External AI NEVER receives raw values classified as
| secrets" this test proves closed.
*/

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

/**
 * @return array<int, string>
 */
function historyTestSseTextChunks(string $text): array
{
    return [
        'data: '.json_encode(['choices' => [['delta' => ['content' => $text]]]])."\n\n",
        'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]])."\n\n",
        "data: [DONE]\n\n",
    ];
}

function historyTestSseResponse(string $text): MockResponse
{
    return new MockResponse(historyTestSseTextChunks($text), ['response_headers' => ['content-type' => 'text/event-stream']]);
}

/**
 * Same capturing helper as tests/Feature/Ai/AiRedactionAndInjectionTest.php
 * (kept local to this file so it stays a single self-contained repro of
 * the history-replay leak, independent of that file's fixtures).
 *
 * @param  array<int, string>  $capturedBodies
 */
function historyTestCapturingMockHttpClient(array &$capturedBodies): MockHttpClient
{
    return new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBodies): MockResponse {
        if ($method === 'GET') {
            return new MockResponse('{"data":[],"models":[]}', ['http_code' => 200]);
        }

        $capturedBodies[] = (string) ($options['body'] ?? '');

        return historyTestSseResponse('Understood.');
    });
}

function historyTestHostedConfig(): AiProviderConfiguration
{
    return new AiProviderConfiguration(
        activeProvider: 'hosted',
        hostedBaseUrl: 'https://api.example.com/v1',
        hostedModel: 'gpt-test',
        hostedApiKey: 'sk-should-never-be-a-secret-fixture-value',
        ollamaBaseUrl: null,
        ollamaModel: null,
        ollamaAllowUnredacted: false,
    );
}

function historyTestOllamaConfig(bool $allowUnredacted): AiProviderConfiguration
{
    return new AiProviderConfiguration(
        activeProvider: 'ollama',
        hostedBaseUrl: null,
        hostedModel: null,
        hostedApiKey: null,
        ollamaBaseUrl: 'http://ollama:11434/v1',
        ollamaModel: 'llama3.2',
        ollamaAllowUnredacted: $allowUnredacted,
    );
}

/*
|--------------------------------------------------------------------------
| The core leak: switching provider mid-conversation must not replay a
| raw secret from history to a hosted provider
|--------------------------------------------------------------------------
*/

it('never replays a raw secret from prior conversation HISTORY when the conversation continues on a hosted provider', function () {
    // A configured secret CraftKeeper already knows about (Task 4's
    // Secret store — the same source SecretRedactor::configuredSecretValues()
    // reads from).
    Secret::put('rcon.password', 'history-leak-secret-42');

    // Simulate the exact aftermath of an earlier Ollama-unredacted turn:
    // a stored assistant AiMessage whose `content` contains the raw
    // secret value verbatim. This is deliberately created directly
    // rather than by actually running an unredacted turn first — it is
    // the smallest possible reproduction of "a raw secret is already
    // sitting in this conversation's history", which is the precondition
    // the bug report identifies, regardless of exactly how it got there.
    $conversation = AiConversation::query()->create(['provider' => 'ollama']);

    AiMessage::query()->create([
        'ai_conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'What is the RCON password?',
        'provider' => 'ollama',
    ]);

    AiMessage::query()->create([
        'ai_conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'It is history-leak-secret-42.',
        'provider' => 'ollama',
    ]);

    // The operator now switches the GLOBAL provider to a HOSTED,
    // OpenAI-compatible provider and continues the SAME conversation.
    $capturedBodies = [];
    $mockHttpClient = historyTestCapturingMockHttpClient($capturedBodies);
    app()->instance(AiManager::class, new AiManager($mockHttpClient, historyTestHostedConfig()));

    app(AssistantService::class)->sendMessage($conversation, 'Can you remind me what it was?', new ContextRequest);

    expect($capturedBodies)->not->toBeEmpty();

    foreach ($capturedBodies as $body) {
        expect($body)->not->toContain('history-leak-secret-42');
    }
});

it('never replays a raw discovered secret from prior conversation HISTORY when the conversation continues on a hosted provider', function () {
    // A schema-discovered secret (Task 7) rather than a Secret-store
    // value — the other half of SecretRedactor::redactKnownSecrets()'s
    // "configured + discovered" ambiguity resolution (see its own
    // docblock), so this file proves the whole-request gate covers BOTH
    // sources, not just the Secret store.
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\nrcon.password=discovered-history-leak-99\n");

    $conversation = AiConversation::query()->create([
        'provider' => 'ollama',
        'context_scope' => ['configPath' => 'server.properties'],
    ]);

    AiMessage::query()->create([
        'ai_conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'The rcon.password field is set to discovered-history-leak-99.',
        'provider' => 'ollama',
    ]);

    $capturedBodies = [];
    $mockHttpClient = historyTestCapturingMockHttpClient($capturedBodies);
    app()->instance(AiManager::class, new AiManager($mockHttpClient, historyTestHostedConfig()));

    app(AssistantService::class)->sendMessage($conversation, 'Remind me what that was.', new ContextRequest('server.properties'));

    expect($capturedBodies)->not->toBeEmpty();

    foreach ($capturedBodies as $body) {
        expect($body)->not->toContain('discovered-history-leak-99');
    }
});

/*
|--------------------------------------------------------------------------
| Keep green: single-turn hosted redaction and the Ollama opt-in are
| unaffected by the whole-request gate
|--------------------------------------------------------------------------
*/

it('still redacts a single-turn hosted conversation with no prior history', function () {
    Secret::put('rcon.password', 'single-turn-secret-1');

    $capturedBodies = [];
    $mockHttpClient = historyTestCapturingMockHttpClient($capturedBodies);
    app()->instance(AiManager::class, new AiManager($mockHttpClient, historyTestHostedConfig()));

    $conversation = AiConversation::query()->create();
    app(AssistantService::class)->sendMessage($conversation, 'My rcon password is single-turn-secret-1, is that ok to share?', new ContextRequest);

    expect($capturedBodies)->not->toBeEmpty();

    foreach ($capturedBodies as $body) {
        expect($body)->not->toContain('single-turn-secret-1');
    }
});

it('still sends unredacted HISTORY to Ollama when the explicit opt-in is enabled for the whole conversation', function () {
    Secret::put('rcon.password', 'ollama-history-optin-secret');

    $conversation = AiConversation::query()->create(['provider' => 'ollama']);

    AiMessage::query()->create([
        'ai_conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'It is ollama-history-optin-secret.',
        'provider' => 'ollama',
    ]);

    $capturedBodies = [];
    $mockHttpClient = historyTestCapturingMockHttpClient($capturedBodies);
    app()->instance(AiManager::class, new AiManager($mockHttpClient, historyTestOllamaConfig(allowUnredacted: true)));

    app(AssistantService::class)->sendMessage($conversation, 'Remind me again?', new ContextRequest);

    $joined = implode('', $capturedBodies);
    expect($joined)->toContain('ollama-history-optin-secret');
});

it('redacts HISTORY sent to Ollama by default when the opt-in is off, even if an earlier turn was unredacted', function () {
    Secret::put('rcon.password', 'ollama-history-default-secret');

    $conversation = AiConversation::query()->create(['provider' => 'ollama']);

    AiMessage::query()->create([
        'ai_conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'It is ollama-history-default-secret.',
        'provider' => 'ollama',
    ]);

    $capturedBodies = [];
    $mockHttpClient = historyTestCapturingMockHttpClient($capturedBodies);
    app()->instance(AiManager::class, new AiManager($mockHttpClient, historyTestOllamaConfig(allowUnredacted: false)));

    app(AssistantService::class)->sendMessage($conversation, 'Remind me again?', new ContextRequest);

    foreach ($capturedBodies as $body) {
        expect($body)->not->toContain('ollama-history-default-secret');
    }
});
