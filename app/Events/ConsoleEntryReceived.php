<?php

namespace App\Events;

use App\Models\ConsoleEntry;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One newly-tailed console line, broadcast in real time on the private
 * `server.console` channel (authorized in routes/channels.php to the
 * signed-in admin only — mirrors App\Events\OperationUpdated's
 * `operations.{id}` channel-auth pattern from Task 5).
 *
 * broadcastWith() carries exactly the SAME sanitized, bounded string
 * App\Models\ConsoleEntry persists — App\Server\LogTailService strips
 * ANSI/control sequences and caps line length at MAX_ENTRY_BYTES (16 KiB)
 * BEFORE either this event is built or the row is written, so there is
 * exactly one bounded/sanitized value in play, not two that could drift.
 * Log content is the Minecraft server's own stdout; nothing here adds any
 * value sourced from elsewhere (no secrets, no CraftKeeper-internal
 * state).
 *
 * Implements ShouldDispatchAfterCommit for the same reason
 * OperationUpdated does (see its docblock and Task 5's decisions.md
 * entry): a client must never observe a console line for a database write
 * that could still roll back.
 */
class ConsoleEntryReceived implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    private function __construct(
        public readonly int $id,
        public readonly string $line,
        public readonly string $occurredAt,
    ) {}

    public static function fromEntry(ConsoleEntry $entry): self
    {
        return new self(
            id: $entry->id,
            line: $entry->line,
            occurredAt: $entry->occurred_at->toIso8601String(),
        );
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.console')];
    }

    public function broadcastAs(): string
    {
        return 'console.entry';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'line' => $this->line,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
