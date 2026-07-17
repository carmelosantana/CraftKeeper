<?php

namespace App\Events;

use App\Models\Operation;
use App\Operations\OperationRisk;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A sanitized, real-time progress projection of an Operation, broadcast on
 * its private `operations.{id}` channel (authorized in routes/channels.php
 * to the signed-in admin only).
 *
 * broadcastWith() is a deliberate allow-list of scalar fields. It NEVER
 * includes the operation's redacted_input/metadata or its `target` — even
 * though redacted_input is already redacted by the time it reaches the
 * database (see App\Operations\InputRedactor), `target` is raw,
 * caller-supplied text for some operation types (e.g. rcon.command, where
 * it is the literal command text) and Task 10's command-aware redaction
 * doesn't exist yet. Excluding both outright means there is no path — now
 * or if a future edit adds fields to Operation — by which a secret value
 * (RCON password, API token, hosted-AI payload, uploaded JAR bytes) can
 * reach this event's wire payload.
 *
 * Implements ShouldDispatchAfterCommit so the event (including the
 * broadcast) is only ever dispatched once the enclosing database write has
 * actually committed — clients never see a status update for a row that
 * could still roll back.
 */
class OperationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    private function __construct(
        public readonly string $operationId,
        public readonly OperationType $type,
        public readonly OperationStatus $status,
        public readonly OperationRisk $risk,
        public readonly ?string $errorCode,
        public readonly ?string $outcome,
        public readonly ?string $updatedAt,
    ) {}

    public static function fromOperation(Operation $operation): self
    {
        return new self(
            operationId: $operation->id,
            type: $operation->type,
            status: $operation->status,
            risk: $operation->risk,
            errorCode: $operation->error_code,
            outcome: $operation->outcome,
            updatedAt: $operation->updated_at?->toIso8601String(),
        );
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("operations.{$this->operationId}")];
    }

    public function broadcastAs(): string
    {
        return 'operation.updated';
    }

    /**
     * The sanitized wire payload — see class docblock for what is
     * deliberately excluded and why.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->operationId,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'risk' => $this->risk->value,
            'error_code' => $this->errorCode,
            'outcome' => $this->outcome,
            'updated_at' => $this->updatedAt,
        ];
    }
}
