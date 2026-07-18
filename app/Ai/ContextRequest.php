<?php

namespace App\Ai;

/**
 * What App\Ai\ContextBuilder should assemble context for: the config path
 * (if any) the operator was viewing when they opened the assistant — see
 * resources/js/features/assistant/AssistantDrawer.tsx, which inherits
 * this from whatever config/plugin/server page it was opened from — and
 * whether an UNREDACTED excerpt is even allowed for this turn (decided by
 * the caller from the resolved provider kind + the operator's explicit
 * `ai.ollama.allow_unredacted` opt-in — see App\Ai\AssistantService; never
 * decided by ContextBuilder itself, so the redaction default can never be
 * silently bypassed by a bug inside this class alone).
 */
final readonly class ContextRequest
{
    public function __construct(
        public ?string $configPath = null,
        public bool $allowUnredacted = false,
    ) {}
}
