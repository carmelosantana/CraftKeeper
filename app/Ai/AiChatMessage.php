<?php

namespace App\Ai;

/**
 * One prior turn of conversation history handed to an App\Ai\AiProvider —
 * framework-agnostic (not a carmelosantana/php-agents message type), so
 * App\Ai\AssistantService can build it straight from persisted
 * App\Models\AiMessage rows without depending on the vendor package
 * outside App\Ai\Providers\*.
 */
final readonly class AiChatMessage
{
    public function __construct(
        public string $role,
        public string $content,
    ) {}
}
