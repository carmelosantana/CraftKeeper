<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Operation;

/**
 * Shared "operation summary" row shape used by Overview, Activity, and
 * Console — one place that decides what an operation looks like once
 * it's on screen, so the three pages can't quietly drift into three
 * different vocabularies for the same underlying Operation.
 *
 * `target` is safe to surface here: for OperationType::RconCommand it is
 * ALREADY the display-safe, possibly-redacted command text
 * App\Console\RconCommandService::proposeCommand() built (Task 10) —
 * never the raw secret-shaped text for a "login ..."-style command. Every
 * other operation type's target (a config file path, a plugin id, the
 * literal string "server") was never secret-shaped to begin with. This is
 * a narrower, read-only presentation concern than App\Events\
 * OperationUpdated::broadcastWith()'s own allow-list (Task 5), which
 * still deliberately excludes `target` for its own, unrelated reason
 * (that class predates Task 10's redaction and has no way to know it
 * exists) — this trait does not change that broadcast payload.
 */
trait PresentsOperations
{
    /**
     * @return array<string, mixed>
     */
    protected function presentOperationSummary(Operation $operation): array
    {
        return [
            'id' => $operation->id,
            'type' => $operation->type->value,
            'status' => $operation->status->value,
            'risk' => $operation->risk->value,
            'target' => $operation->target,
            'actorType' => $operation->author_type->value,
            'actorId' => $operation->author_id,
            'actorOrigin' => $operation->author_origin,
            'correlationId' => $operation->correlation_id,
            'createdAt' => $operation->created_at?->toIso8601String(),
            'finishedAt' => $operation->finished_at?->toIso8601String(),
            'outcome' => $operation->outcome,
            'errorCode' => $operation->error_code,
        ];
    }
}
