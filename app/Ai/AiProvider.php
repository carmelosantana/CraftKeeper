<?php

namespace App\Ai;

/**
 * The plan's Stable Interface, reproduced exactly
 * (docs/superpowers/plans/2026-07-17-craftkeeper-v1.md, "Stable
 * Interfaces"):
 *
 *     interface AiProvider
 *     {
 *         public function health(): AiProviderHealth;
 *         public function stream(AiRequest $request): iterable;
 *     }
 *
 * Implemented by App\Ai\Providers\OpenAiCompatibleProvider (hosted) and
 * App\Ai\Providers\OllamaProvider (local). App\Ai\AiManager is the only
 * caller that ever constructs one; every other consumer (App\Ai\
 * AssistantService, App\Ai\Tools\*) depends only on this interface, never
 * on a concrete provider or on carmelosantana/php-agents directly.
 */
interface AiProvider
{
    public function health(): AiProviderHealth;

    /**
     * @return iterable<AiStreamEvent>
     */
    public function stream(AiRequest $request): iterable;
}
