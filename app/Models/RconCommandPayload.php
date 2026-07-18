<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The REAL (unredacted) text of a console command whose input matched one
 * of App\Console\CommandPolicy::looksLikeSecret()'s configured secret
 * patterns — e.g. a plugin's `/login <password>`-shaped command.
 *
 * Mirrors App\Models\ConfigChangePayload's pattern exactly (Task 8), for
 * the identical reason: an Operation's own columns (`target`,
 * `redacted_input`, every ChangeProposal/AuditEvent row tied to it) are a
 * tested invariant of holding only pre-redacted/display-safe data — see
 * docs/architecture/decisions.md (Task 5, Task 10) — so the one genuinely
 * raw value a secret-shaped RCON command needs at execute() time lives
 * here instead, in its own single-purpose, `#[Hidden]`, encrypted-at-rest
 * column, read by exactly one caller:
 * App\Operations\Handlers\RconCommandHandler.
 *
 * Most console commands are NOT secret-like (`stop`, `op Steve`, `ban
 * Steve`, `gamerule keepInventory true`, ...) and never get a row here at
 * all — App\Console\RconCommandService::proposeCommand() only creates one
 * when CommandPolicy::looksLikeSecret() is true. For every other command
 * the real text needed at execute() time is simply the Operation's own
 * (unredacted but non-secret) `redacted_input['command']`, exactly as
 * Task 5's generic OperationRequest::rconCommand() already stores it.
 *
 * Data minimization: deleteForOperation() is called from exactly two
 * places once the raw value can never legitimately be needed again —
 * App\Operations\Handlers\RconCommandHandler::execute() (after the
 * command has actually been sent, success or failure) and
 * App\Operations\OperationService::reject() (a rejected operation never
 * executes, so any stashed payload for it is immediately dead) — mirroring
 * both of ConfigChangePayload's own call sites.
 *
 * @property int $id
 * @property string $operation_id
 * @property string $command
 */
#[Fillable(['operation_id', 'command'])]
#[Hidden(['command'])]
class RconCommandPayload extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Operation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Delete this operation's raw command payload, if any. A plain,
     * column-blind DELETE — safe to call unconditionally even when no row
     * exists (the common, non-secret-command case).
     */
    public static function deleteForOperation(string $operationId): void
    {
        static::query()->where('operation_id', $operationId)->delete();
    }
}
