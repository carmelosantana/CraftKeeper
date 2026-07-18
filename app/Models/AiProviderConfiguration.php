<?php

namespace App\Models;

/**
 * The resolved AI provider settings: which provider kind is active (if
 * any), and its connection details. Backed by the existing encrypted
 * Setting/Secret key-value store (Task 4) rather than a dedicated table —
 * `Setting::get('ai.provider')`/`Secret::get('ai.api_key')` are the exact
 * keys App\Http\Controllers\OnboardingController::storeAi() already
 * writes (see its docblock: "Real wiring lands in Task 16"), so this class
 * is additive on top of that, not a competing store. `hosted.*`/
 * `ollama.*` settings have no onboarding UI yet (out of scope for this
 * task — see docs/architecture/decisions.md) but are fully functional via
 * `Setting::put()`, ready for a future Integrations settings screen.
 *
 * Deliberately a plain, publicly-constructible value object (not an
 * Eloquent model, despite living in app/Models alongside the task's other
 * new models) so App\Ai\AiManager can be unit tested with an explicit
 * configuration and no database at all — see tests/Unit/Ai/AiManagerTest.php.
 */
final readonly class AiProviderConfiguration
{
    public function __construct(
        public ?string $activeProvider,
        public ?string $hostedBaseUrl,
        public ?string $hostedModel,
        public ?string $hostedApiKey,
        public ?string $ollamaBaseUrl,
        public ?string $ollamaModel,
        public bool $ollamaAllowUnredacted,
    ) {}

    /**
     * Resolve the current configuration from the Setting/Secret store.
     * `ai.provider` is free text today (the onboarding form's placeholder
     * input, e.g. "openai", "anthropic", "ollama" — see
     * OnboardingController::ai()); anything other than the literal
     * "ollama" is treated as "use the hosted OpenAI-compatible provider
     * slot", which is itself only actually usable once `ai.hosted.*` is
     * also configured (see isConfigured()) — a bare provider name from
     * onboarding is never, by itself, enough to make provider() non-null.
     */
    public static function load(): self
    {
        $provider = Setting::get('ai.provider');
        $normalized = trim((string) $provider) === ''
            ? null
            : (strtolower(trim((string) $provider)) === 'ollama' ? 'ollama' : 'hosted');

        return new self(
            activeProvider: $normalized,
            hostedBaseUrl: self::nullIfBlank(Setting::get('ai.hosted.base_url')),
            hostedModel: self::nullIfBlank(Setting::get('ai.hosted.model')),
            hostedApiKey: self::nullIfBlank(Secret::get('ai.api_key')),
            ollamaBaseUrl: self::nullIfBlank(Setting::get('ai.ollama.base_url')) ?? 'http://localhost:11434/v1',
            ollamaModel: self::nullIfBlank(Setting::get('ai.ollama.model')) ?? 'llama3.2',
            ollamaAllowUnredacted: Setting::get('ai.ollama.allow_unredacted') === '1',
        );
    }

    /**
     * Whether the active provider has everything it needs to be
     * constructed. A hosted provider needs a base URL, a model, AND a key
     * (a hosted API without a key is never silently attempted); Ollama
     * needs only a base URL and model (no key, by design — see the task
     * brief).
     */
    public function isConfigured(): bool
    {
        return match ($this->activeProvider) {
            'hosted' => $this->hostedBaseUrl !== null && $this->hostedModel !== null && $this->hostedApiKey !== null,
            'ollama' => $this->ollamaBaseUrl !== null && $this->ollamaModel !== null,
            default => false,
        };
    }

    private static function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
