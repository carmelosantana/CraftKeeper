<?php

namespace App\Ai\Providers;

use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider as VendorOpenAiCompatibleProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A hosted, OpenAI-compatible AI provider — base URL, model, and API key
 * are all configurable (App\Models\AiProviderConfiguration), never
 * hard-coded to a specific vendor's endpoint. This is the provider every
 * REDACTED context is built for by default (see App\Ai\ContextBuilder);
 * it never sees an unredacted excerpt, unlike the local Ollama provider's
 * explicit opt-in.
 *
 * Delegates all HTTP work to carmelosantana/php-agents'
 * OpenAICompatibleProvider — see App\Ai\Providers\AbstractAiProvider's
 * docblock for why (Symfony HttpClient under the hood, not Laravel's Http
 * facade). $httpClient defaults to defaultHttpClient() — 2s connect / 5s
 * response, no retries (config('craftkeeper.ai.*')) — but every test that
 * constructs this class passes its own Symfony
 * Symfony\Component\HttpClient\MockHttpClient explicitly, so no test ever
 * touches the real network.
 */
final class OpenAiCompatibleProvider extends AbstractAiProvider
{
    public function __construct(
        string $model,
        string $baseUrl,
        ?string $apiKey = null,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct(new VendorOpenAiCompatibleProvider(
            model: $model,
            baseUrl: $baseUrl,
            apiKey: $apiKey ?? '',
            httpClient: $httpClient ?? self::defaultHttpClient(),
        ));
    }

    public static function defaultHttpClient(): HttpClientInterface
    {
        return HttpClient::create([
            'max_connect_duration' => (float) config('craftkeeper.ai.connect_timeout_seconds'),
            'timeout' => (float) config('craftkeeper.ai.response_timeout_seconds'),
        ]);
    }

    protected function unavailableReason(): string
    {
        return 'The hosted AI provider did not respond.';
    }
}
