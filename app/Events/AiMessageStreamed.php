<?php

namespace App\Events;

use App\Models\AiMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The final, persisted App\Models\AiMessage for one assistant turn,
 * broadcast on the conversation's private `ai.conversations.{id}` channel
 * once it has actually committed — ShouldDispatchAfterCommit, matching
 * App\Events\OperationUpdated's rationale exactly: a connected client
 * (e.g. a second open tab) must never see a message that could still roll
 * back.
 *
 * broadcastWith() carries only what App\Models\AiMessage itself already
 * guarantees is safe (see that model's docblock): the final answer text,
 * citations, a redacted tool-call summary, and redaction disclosures —
 * never a raw secret.
 */
class AiMessageStreamed implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly AiMessage $message,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("ai.conversations.{$this->message->ai_conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'assistant.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'role' => $this->message->role,
            'content' => $this->message->content,
            'citations' => $this->message->citations ?? [],
            'toolCalls' => $this->message->tool_calls ?? [],
            'redactionDisclosures' => $this->message->redaction_disclosures ?? [],
            'provider' => $this->message->provider,
            'error' => $this->message->error,
            'createdAt' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
