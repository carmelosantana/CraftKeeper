<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One EPHEMERAL chunk of an in-progress assistant turn — a partial answer
 * delta, or a tool call starting/finishing — broadcast in real time on
 * the conversation's PRIVATE `ai.conversations.{id}` channel (admin-only;
 * see routes/channels.php, mirroring App\Events\OperationUpdated's
 * `operations.{id}` pattern from Task 5).
 *
 * ShouldBroadcastNow (not ShouldBroadcast+ShouldDispatchAfterCommit, the
 * pattern App\Events\OperationUpdated/ConsoleEntryReceived use): unlike
 * those, no database row is ever written per chunk — there is nothing to
 * roll back, so gating on a transaction commit would only add latency for
 * no safety benefit. The FINAL answer, once the whole turn completes, IS
 * persisted (App\Models\AiMessage) and broadcast separately via
 * App\Events\AiMessageStreamed, which DOES wait for that commit.
 *
 * `payload` is a deliberate scalar/array allow-list, same discipline as
 * every other broadcast event in this app — see broadcastWith().
 */
class AiAssistantStreamEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly array $payload,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("ai.conversations.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'assistant.stream';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
