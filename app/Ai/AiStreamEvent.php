<?php

namespace App\Ai;

/**
 * One event yielded from App\Ai\AiProvider::stream(): a partial answer
 * delta, a tool invocation starting or finishing, the final answer, or a
 * provider error. A discriminated union expressed as named constructors
 * over one readonly shape (mirroring how Task 9/12's DTOs are built)
 * rather than several event classes, since App\Ai\AssistantService only
 * ever needs to switch on `$event->type` once per chunk.
 *
 * `toolResult` events fire for EVERY tool call outcome — success, a
 * declared tool error, AND a call denied by policy or naming an unknown
 * tool (see App\Ai\Providers\AbstractAiProvider and
 * App\Ai\Tools\AllowedToolsPolicy) — so a prompt-injection attempt that
 * tries to make the model call a nonexistent "approve"/"execute" tool
 * still surfaces here, transparently, as a denied/error result rather
 * than silently vanishing or crashing the stream.
 */
final readonly class AiStreamEvent
{
    /**
     * @param  array<string, mixed>  $toolArguments
     * @param  list<array{title: string, url: string}>  $citations
     */
    private function __construct(
        public string $type,
        public string $text = '',
        public ?string $toolName = null,
        public array $toolArguments = [],
        public ?string $toolStatus = null,
        public string $toolSummary = '',
        public array $citations = [],
        public ?string $errorMessage = null,
    ) {}

    public static function delta(string $text): self
    {
        return new self('delta', text: $text);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $name, array $arguments): self
    {
        return new self('tool_call', toolName: $name, toolArguments: $arguments);
    }

    public static function toolResult(string $name, string $status, string $summary): self
    {
        return new self('tool_result', toolName: $name, toolStatus: $status, toolSummary: $summary);
    }

    /**
     * @param  list<array{title: string, url: string}>  $citations
     */
    public static function done(string $text, array $citations = []): self
    {
        return new self('done', text: $text, citations: $citations);
    }

    public static function error(string $message): self
    {
        return new self('error', errorMessage: $message);
    }
}
