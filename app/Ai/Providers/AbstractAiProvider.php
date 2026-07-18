<?php

namespace App\Ai\Providers;

use App\Ai\AiProvider;
use App\Ai\AiProviderHealth;
use App\Ai\AiRequest;
use App\Ai\AiStreamEvent;
use App\Ai\AssistantAgent;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Fiber;
use SplObserver;
use SplSubject;
use Throwable;

/**
 * Shared plumbing behind both App\Ai\Providers\OpenAiCompatibleProvider
 * (hosted) and App\Ai\Providers\OllamaProvider (local): a carmelosantana/
 * php-agents ProviderInterface is delegated to for the actual HTTP work,
 * and App\Ai\AiProvider::stream() drives App\Ai\AssistantAgent's tool-
 * calling loop, translating its observer events into App\Ai\AiStreamEvent
 * instances as they happen.
 *
 * carmelosantana/php-agents' AbstractAgent::run() is a single blocking
 * call that only exposes partial text (and tool progress) through the
 * SplSubject/SplObserver pattern — see vendor/carmelosantana/php-agents/
 * src/Agent/AbstractAgent.php's notify() calls for `agent.text_delta`/
 * `agent.tool_call`/`agent.tool_result`. To turn that into a genuinely
 * incremental `iterable` (so App\Http\Controllers\AssistantController can
 * broadcast each chunk over Reverb as it actually arrives, not all at
 * once after the whole answer is done), run() is executed inside a PHP
 * Fiber: the attached observer calls Fiber::suspend() with each event,
 * and this method's generator loop resumes the fiber once the caller has
 * consumed that event. This is standard cooperative-coroutine use of
 * Fiber (stable since PHP 8.1) — no threads, no async runtime, nothing
 * vendor-specific.
 *
 * health()/stream() never let a Throwable escape without being turned
 * into either AiProviderHealth::down() or an AiStreamEvent::error() — see
 * each method's own note. AssistantAgent's `tool_result` events (Phase 3
 * of the vendor loop) fire uniformly for successful, tool-declared-error,
 * AND policy-denied/unknown-tool outcomes, so a prompt-injection attempt
 * that gets the model to try calling something outside App\Ai\Tools\
 * AllowedToolsPolicy's allowlist still surfaces here as a transparent
 * denial, never a silent success and never a crash.
 */
abstract class AbstractAiProvider implements AiProvider
{
    public function __construct(
        protected readonly ProviderInterface $delegate,
    ) {}

    /**
     * A human-readable reason shown when health() finds the provider
     * unreachable — distinguishes "the hosted API didn't respond" from
     * "the local Ollama server didn't respond" in the UI's PageState
     * (`ai-unavailable`).
     */
    abstract protected function unavailableReason(): string;

    /**
     * The underlying vendor provider's isAvailable() already catches
     * every Throwable itself and returns a plain bool (see
     * vendor/carmelosantana/php-agents/src/Provider/OpenAICompatibleProvider.php) —
     * this wraps that in timing and an honest reason, and additionally
     * catches anything that could still somehow escape (defense in depth;
     * "AI outage must not fail health/config/plugins/API" is a hard
     * requirement, not a best effort).
     */
    public function health(): AiProviderHealth
    {
        $start = microtime(true);

        try {
            $available = $this->delegate->isAvailable();
        } catch (Throwable) {
            return AiProviderHealth::down($this->unavailableReason(), (microtime(true) - $start) * 1000);
        }

        $latencyMs = (microtime(true) - $start) * 1000;

        return $available
            ? AiProviderHealth::up($latencyMs)
            : AiProviderHealth::down($this->unavailableReason(), $latencyMs);
    }

    /**
     * @return iterable<AiStreamEvent>
     */
    public function stream(AiRequest $request): iterable
    {
        $agent = new AssistantAgent($this->delegate, $request->tools, $request->systemPrompt);

        $fiber = new Fiber(function () use ($agent, $request): Output {
            $observer = new class implements SplObserver
            {
                public function update(SplSubject $subject): void
                {
                    /** @var AssistantAgent $subject */
                    match ($subject->lastEvent()) {
                        'agent.text_delta' => Fiber::suspend(AiStreamEvent::delta((string) $subject->lastEventData())),
                        'agent.tool_call' => self::suspendToolCall($subject->lastEventData()),
                        'agent.tool_result' => self::suspendToolResult($subject->lastEventData()),
                        default => null,
                    };
                }

                private static function suspendToolCall(mixed $data): void
                {
                    if ($data instanceof ToolCall) {
                        Fiber::suspend(AiStreamEvent::toolCall($data->name, $data->arguments));
                    }
                }

                private static function suspendToolResult(mixed $data): void
                {
                    if ($data instanceof ToolResult) {
                        Fiber::suspend(AiStreamEvent::toolResult(
                            'tool',
                            $data->status === ToolResultStatus::Success ? 'success' : 'error',
                            $data->content,
                        ));
                    }
                }
            };

            $agent->attach($observer);

            $history = new Conversation;

            foreach ($request->history as $message) {
                $history->add($message->role === 'assistant'
                    ? new AssistantMessage($message->content)
                    : new UserMessage($message->content));
            }

            return $agent->run(new UserMessage($request->userMessage), $history);
        });

        try {
            $event = $fiber->start();

            while (! $fiber->isTerminated()) {
                /** @var AiStreamEvent $event */
                yield $event;
                $event = $fiber->resume();
            }
        } catch (Throwable $e) {
            yield AiStreamEvent::error($e->getMessage());

            return;
        }

        $output = $fiber->getReturn();

        if ($output->finishReason === AgentFinishReason::Error) {
            yield AiStreamEvent::error($output->content);

            return;
        }

        yield AiStreamEvent::done($output->content);
    }
}
