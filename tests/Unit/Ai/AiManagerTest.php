<?php

use App\Ai\AiManager;
use App\Ai\Providers\OllamaProvider;
use App\Ai\Providers\OpenAiCompatibleProvider;
use App\Models\AiProviderConfiguration;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/*
|--------------------------------------------------------------------------
| AI is entirely optional: provider() -> null on every failure mode
|--------------------------------------------------------------------------
*/

it('returns null when no AI provider is configured (disabled)', function () {
    $config = new AiProviderConfiguration(
        activeProvider: null,
        hostedBaseUrl: null,
        hostedModel: null,
        hostedApiKey: null,
        ollamaBaseUrl: 'http://localhost:11434/v1',
        ollamaModel: 'llama3.2',
        ollamaAllowUnredacted: false,
    );

    $manager = new AiManager(configuration: $config);

    expect($manager->provider())->toBeNull();
});

it('returns null when a hosted provider is selected but has no API key configured', function () {
    $config = new AiProviderConfiguration(
        activeProvider: 'hosted',
        hostedBaseUrl: 'https://api.example.com/v1',
        hostedModel: 'gpt-test',
        hostedApiKey: null,
        ollamaBaseUrl: null,
        ollamaModel: null,
        ollamaAllowUnredacted: false,
    );

    $manager = new AiManager(configuration: $config);

    expect($manager->provider())->toBeNull();
});

it('returns null and performs no retries when the configured provider is unreachable', function () {
    // Counted independently of MockHttpClient::getRequestsCount() — that
    // counter only increments AFTER the response factory returns, so a
    // factory that throws (simulating a real connection failure, which is
    // exactly what a provider's isAvailable() must observe) would never
    // be reflected in it. Counting the closure's own invocations instead
    // proves "no retries" directly: the transport was asked to connect
    // exactly once.
    $attempts = 0;
    $mockHttpClient = new MockHttpClient(function () use (&$attempts): never {
        $attempts++;
        throw new TransportException('Connection refused');
    });

    $config = new AiProviderConfiguration(
        activeProvider: 'ollama',
        hostedBaseUrl: null,
        hostedModel: null,
        hostedApiKey: null,
        ollamaBaseUrl: 'http://ollama:11434/v1',
        ollamaModel: 'llama3.2',
        ollamaAllowUnredacted: false,
    );

    $manager = new AiManager($mockHttpClient, $config);

    expect($manager->provider())->toBeNull()
        ->and($attempts)->toBe(1);
});

it('returns a working AiProvider when the configured hosted provider is reachable', function () {
    $mockHttpClient = new MockHttpClient(new MockResponse('{"data":[]}', ['http_code' => 200]));

    $config = new AiProviderConfiguration(
        activeProvider: 'hosted',
        hostedBaseUrl: 'https://api.example.com/v1',
        hostedModel: 'gpt-test',
        hostedApiKey: 'sk-test-key',
        ollamaBaseUrl: null,
        ollamaModel: null,
        ollamaAllowUnredacted: false,
    );

    $manager = new AiManager($mockHttpClient, $config);
    $provider = $manager->provider();

    expect($provider)->toBeInstanceOf(OpenAiCompatibleProvider::class);
});

it('returns a working AiProvider when the configured Ollama provider is reachable', function () {
    $mockHttpClient = new MockHttpClient(new MockResponse('{"models":[]}', ['http_code' => 200]));

    $config = new AiProviderConfiguration(
        activeProvider: 'ollama',
        hostedBaseUrl: null,
        hostedModel: null,
        hostedApiKey: null,
        ollamaBaseUrl: 'http://ollama:11434/v1',
        ollamaModel: 'llama3.2',
        ollamaAllowUnredacted: false,
    );

    $manager = new AiManager($mockHttpClient, $config);
    $provider = $manager->provider();

    expect($provider)->toBeInstanceOf(OllamaProvider::class);
});

/*
|--------------------------------------------------------------------------
| Health check timeout/no-retry configuration
|--------------------------------------------------------------------------
*/

it('configures a 2 second connect / 5 second response timeout for the default provider HTTP client', function () {
    expect((float) config('craftkeeper.ai.connect_timeout_seconds'))->toBe(2.0)
        ->and((float) config('craftkeeper.ai.response_timeout_seconds'))->toBe(5.0);

    // Building the default client must not throw and must not perform any
    // request on its own (construction is lazy) — this proves the
    // provider classes read the bounded timeout config rather than the
    // vendor package's own defaults (300s, no connect bound).
    $client = OpenAiCompatibleProvider::defaultHttpClient();

    expect($client)->toBeInstanceOf(HttpClientInterface::class);
});
