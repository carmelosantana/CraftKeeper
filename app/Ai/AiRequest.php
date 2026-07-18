<?php

namespace App\Ai;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;

/**
 * Everything an App\Ai\AiProvider needs for one turn: the conversation so
 * far, the operator's new message, the (already provider-appropriate —
 * redacted for hosted, possibly unredacted for an opted-in local Ollama;
 * see App\Ai\ContextBuilder) system prompt, and the closed set of tools
 * this turn's agent is allowed to call.
 */
final readonly class AiRequest
{
    /**
     * @param  list<AiChatMessage>  $history
     * @param  list<ToolInterface>  $tools
     */
    public function __construct(
        public string $conversationId,
        public array $history,
        public string $userMessage,
        public string $systemPrompt,
        public array $tools = [],
    ) {}
}
