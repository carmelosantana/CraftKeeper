<?php

namespace App\Ai;

use App\Ai\Providers\OllamaProvider;
use App\Ai\Providers\OpenAiCompatibleProvider;
use App\Models\AiProviderConfiguration;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The single seam every other AI-aware surface (App\Http\Controllers\
 * AssistantController, a future REST/MCP tool) depends on instead of
 * constructing a provider directly. `provider()` returns null whenever AI
 * is disabled (no provider configured), misconfigured (missing base
 * URL/model, or a missing key for the hosted provider), OR configured but
 * currently unreachable — collapsing all three into one "there is no
 * usable AI provider right now" signal, by design: every caller only
 * ever needs to branch on null vs not-null, never on WHY.
 *
 * This is the isolation boundary the task brief's own Step 2 requires:
 * "AiManager::provider() then returns null and only AI controls are
 * disabled." No other application feature (health, configuration,
 * plugins, RCON) ever calls into this class, so a slow or offline AI
 * provider cannot make any of them slow or unavailable either — see
 * tests/Feature/Ai/AiUnavailableTest.php's outage-isolation tests.
 *
 * `$httpClient` is an optional constructor override — production leaves
 * it null (each provider builds its own bounded-timeout, no-retry client;
 * see App\Ai\Providers\OpenAiCompatibleProvider::defaultHttpClient()),
 * tests bind a Symfony\Component\HttpClient\MockHttpClient so a health
 * check or a whole conversation turn never touches the real network.
 * `$configuration` is a similar override, letting App\Ai\AiManager be
 * fully unit tested with an explicit App\Models\AiProviderConfiguration
 * and no database at all (see tests/Unit/Ai/AiManagerTest.php); when
 * null, the real Setting/Secret-backed configuration is loaded lazily,
 * once per provider() call, so a runtime settings change takes effect on
 * the very next request without restarting anything.
 */
final class AiManager
{
    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?AiProviderConfiguration $configuration = null,
    ) {}

    public function configuration(): AiProviderConfiguration
    {
        return $this->configuration ?? AiProviderConfiguration::load();
    }

    /**
     * Resolve the currently usable AI provider, or null. Never throws:
     * every failure mode (disabled, misconfigured, unreachable) collapses
     * to null rather than an exception, so a caller never needs a
     * try/catch just to find out AI isn't available right now.
     */
    public function provider(): ?AiProvider
    {
        $provider = $this->build($this->configuration());

        if ($provider === null) {
            return null;
        }

        return $provider->health()->available ? $provider : null;
    }

    private function build(AiProviderConfiguration $config): ?AiProvider
    {
        if (! $config->isConfigured()) {
            return null;
        }

        return match ($config->activeProvider) {
            'hosted' => new OpenAiCompatibleProvider(
                model: (string) $config->hostedModel,
                baseUrl: (string) $config->hostedBaseUrl,
                apiKey: $config->hostedApiKey,
                httpClient: $this->httpClient,
            ),
            'ollama' => new OllamaProvider(
                model: (string) $config->ollamaModel,
                baseUrl: (string) $config->ollamaBaseUrl,
                httpClient: $this->httpClient,
            ),
            default => null,
        };
    }
}
