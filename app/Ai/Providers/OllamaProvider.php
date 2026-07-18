<?php

namespace App\Ai\Providers;

use CarmeloSantana\PHPAgents\Provider\OllamaProvider as VendorOllamaProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A local Ollama AI provider — base URL and model are configurable
 * (App\Models\AiProviderConfiguration), NO API key, matching the task
 * brief. This is the one provider that MAY receive an unredacted context
 * excerpt, and only after an explicit opt-in setting
 * (`ai.ollama.allow_unredacted`, App\Ai\ContextBuilder) that discloses the
 * trust tradeoff to the operator — the default is redacted for every
 * provider, including this one.
 *
 * Delegates all HTTP work to carmelosantana/php-agents' OllamaProvider —
 * see App\Ai\Providers\OpenAiCompatibleProvider's docblock for the shared
 * timeout/no-retry/HTTP-client-mocking rationale, which applies here
 * identically.
 */
final class OllamaProvider extends AbstractAiProvider
{
    public function __construct(
        string $model,
        string $baseUrl,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct(new VendorOllamaProvider(
            model: $model,
            baseUrl: $baseUrl,
            httpClient: $httpClient ?? OpenAiCompatibleProvider::defaultHttpClient(),
        ));
    }

    protected function unavailableReason(): string
    {
        return 'The local Ollama server did not respond.';
    }
}
