<?php

namespace App\Operations;

use App\Events\OperationUpdated;
use App\Models\AuditEvent;
use App\Models\ChangeProposal;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Models\User;
use App\Operations\Exceptions\IllegalOperationTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the audited operation lifecycle: propose -> approve|reject ->
 * execute -> succeed|fail -> (optionally) roll back. This is the ONLY
 * place an Operation's status may change — every transition is checked
 * against OperationStatus::canTransitionTo() and every change is recorded
 * as an AuditEvent and broadcast on the operation's private channel.
 *
 * Scope is deliberately lifecycle, not execution: propose() never runs
 * anything, and execute() — the extension seam — resolves a handler from
 * OperationHandlerRegistry and degrades cleanly to a Failed operation with
 * a typed error code when no handler is registered, which is true for
 * every OperationType as of this task (no concrete OperationHandler exists
 * yet; see Tasks 8, 10, 15).
 *
 * approve()/reject() are the security-critical boundary: both accept only
 * a real, authenticated App\Models\User (never an OperationAuthor), so
 * there is no code path by which an MCP- or AI-authored operation can
 * approve or reject itself. A human proposing and then approving their own
 * operation is the normal, expected flow for this single-admin product —
 * only non-human authors are excluded, not self-approval.
 *
 * Note: approve() intentionally does NOT invoke execute() automatically.
 * Keeping "was this approved" and "did it run" as separate, explicitly
 * invoked steps keeps this task strictly about the lifecycle — whichever
 * task builds the first real handler decides whether to call execute()
 * synchronously right after approval or dispatch it as a queued job, and
 * doesn't need to unpick an assumption baked in here.
 */
class OperationService
{
    public function __construct(
        private readonly OperationHandlerRegistry $handlers,
    ) {}

    /**
     * Record a proposed mutation. This NEVER executes anything: it
     * persists a Proposed operation, derives its (redacted) change
     * proposals, writes an audit event, and broadcasts the initial state.
     * approved_at is always null on the returned Operation.
     */
    public function propose(OperationRequest $request, OperationAuthor $author): Operation
    {
        return DB::transaction(function () use ($request, $author) {
            $redactedInput = InputRedactor::redact($request->metadata);

            // status is not mass-assignable (see the invariant documented on
            // the model), so it's set explicitly here via forceCreate()
            // rather than folded into an untrusted-shaped array — this is
            // the one place that's allowed to happen.
            $operation = Operation::query()->forceCreate([
                'type' => $request->type,
                'status' => OperationStatus::Proposed,
                'target' => $request->target,
                'risk' => $request->risk,
                'author_type' => $author->type,
                'author_id' => $author->id,
                'author_origin' => $author->origin,
                'redacted_input' => $redactedInput,
                'correlation_id' => (string) Str::uuid(),
            ]);

            $this->recordChangeProposals($operation, $redactedInput);
            $this->audit($operation, 'operation.proposed', $author);
            $this->broadcast($operation);

            return $operation;
        });
    }

    /**
     * Approve a proposed operation. Human-only by type: $approver must be
     * a real App\Models\User, so no MCP/AI actor can ever call this
     * successfully. Illegal transitions (anything other than
     * Proposed -> Approved) throw IllegalOperationTransition instead of
     * being silently ignored.
     */
    public function approve(string $operationId, User $approver): Operation
    {
        $operation = Operation::query()->findOrFail($operationId);
        $author = OperationAuthor::user($approver->getKey());

        return DB::transaction(function () use ($operation, $author) {
            $operation = $this->transition($operation, OperationStatus::Approved);

            $operation->forceFill([
                'approved_by_type' => $author->type,
                'approved_by_id' => $author->id,
                'approved_at' => now(),
            ])->save();

            $this->audit($operation, 'operation.approved', $author);
            $this->broadcast($operation);

            return $operation;
        });
    }

    /**
     * Reject a proposed operation. Human-only by type, symmetrically with
     * approve() — $rejector must be a real App\Models\User. Only legal
     * from Proposed; rejecting anything else throws
     * IllegalOperationTransition.
     */
    public function reject(string $operationId, User $rejector, string $reason): Operation
    {
        $operation = Operation::query()->findOrFail($operationId);
        $author = OperationAuthor::user($rejector->getKey());

        return DB::transaction(function () use ($operation, $author, $reason) {
            $operation = $this->transition($operation, OperationStatus::Rejected);

            $operation->forceFill([
                'rejected_by_type' => $author->type,
                'rejected_by_id' => $author->id,
                'rejected_at' => now(),
                'outcome' => $reason,
            ])->save();

            $this->audit($operation, 'operation.rejected', $author, ['reason' => $reason]);
            $this->broadcast($operation);

            return $operation;
        });
    }

    /**
     * The execution seam. Resolves an OperationHandler for the operation's
     * type from the registry and runs it: Approved -> Running ->
     * Succeeded|Failed. Any exception a handler lets escape is caught and
     * turned into a Failed operation rather than propagating. When no
     * handler is registered for the type — true for every OperationType as
     * of this task — the operation still transitions cleanly to Failed
     * with the stable error code "operation.no_handler_registered"; it
     * never throws or leaves the operation stuck mid-transition.
     *
     * Deliberately two separate transactions, not one wrapping the whole
     * method: the Approved -> Running write commits (and broadcasts)
     * *before* the handler runs, and the terminal write commits
     * separately afterwards. A handler's execute() can be slow (e.g. a
     * plugin download in Task 15) — if this were one transaction, "Running"
     * would never be visible to another request/websocket client until the
     * entire thing finished, defeating the point of realtime progress.
     */
    public function execute(string $operationId): Operation
    {
        $operation = Operation::query()->findOrFail($operationId);

        $operation = DB::transaction(function () use ($operation) {
            $operation = $this->transition($operation, OperationStatus::Running);
            $operation->forceFill(['started_at' => now()])->save();
            $this->audit($operation, 'operation.running', OperationAuthor::system());
            $this->broadcast($operation);

            return $operation;
        });

        $step = OperationStep::query()->create([
            'operation_id' => $operation->id,
            'sequence' => 1,
            'name' => 'execute',
            'status' => OperationStepStatus::Running,
            'started_at' => now(),
        ]);

        $result = $this->runHandler($operation, fn (OperationHandler $handler) => $handler->execute($operation));

        return DB::transaction(function () use ($operation, $step, $result) {
            $step->forceFill([
                'status' => $result->successful ? OperationStepStatus::Succeeded : OperationStepStatus::Failed,
                'finished_at' => now(),
                'error_code' => $result->errorCode,
                'output' => $result->output,
            ])->save();

            $terminal = $result->successful ? OperationStatus::Succeeded : OperationStatus::Failed;
            $operation = $this->transition($operation, $terminal);
            $operation->forceFill([
                'finished_at' => now(),
                'outcome' => $result->message,
                'error_code' => $result->errorCode,
            ])->save();

            $this->audit(
                $operation,
                $result->successful ? 'operation.succeeded' : 'operation.failed',
                OperationAuthor::system(),
                ['error_code' => $result->errorCode],
            );
            $this->broadcast($operation);

            return $operation;
        });
    }

    /**
     * Reverse a Succeeded or Failed operation. $author is an
     * OperationAuthor (not a User) because rollback is not always a new
     * human decision — it may be CraftKeeper itself compensating
     * automatically after a failed post-write verification
     * (OperationAuthor::system()). Degrades cleanly like execute() when no
     * handler is registered.
     */
    public function rollback(string $operationId, OperationAuthor $author): Operation
    {
        $operation = Operation::query()->findOrFail($operationId);

        return DB::transaction(function () use ($operation, $author) {
            $operation = $this->transition($operation, OperationStatus::RolledBack);

            $result = $this->runHandler($operation, fn (OperationHandler $handler) => $handler->rollback($operation));

            $operation->forceFill([
                'finished_at' => now(),
                'outcome' => $result->message,
                'error_code' => $result->successful ? null : $result->errorCode,
            ])->save();

            $this->audit($operation, 'operation.rolled_back', $author, [
                'successful' => $result->successful,
                'error_code' => $result->errorCode,
            ]);
            $this->broadcast($operation);

            return $operation;
        });
    }

    /**
     * Resolve a handler and run $callback against it, converting a
     * missing handler or any escaped Throwable into a typed failure
     * instead of crashing the lifecycle.
     *
     * @param  callable(OperationHandler): OperationResult  $callback
     */
    private function runHandler(Operation $operation, callable $callback): OperationResult
    {
        $handler = $this->handlers->resolve($operation->type);

        if ($handler === null) {
            return OperationResult::failure(
                'operation.no_handler_registered',
                "No operation handler is registered for type \"{$operation->type->value}\".",
            );
        }

        try {
            return $callback($handler);
        } catch (Throwable $e) {
            return OperationResult::failure('operation.handler_exception', $e->getMessage());
        }
    }

    /**
     * Guard every status change through the state machine. Illegal
     * transitions throw rather than being silently ignored.
     */
    private function transition(Operation $operation, OperationStatus $to): Operation
    {
        if (! $operation->status->canTransitionTo($to)) {
            throw new IllegalOperationTransition($operation->status, $to);
        }

        $operation->forceFill(['status' => $to])->save();

        return $operation->fresh() ?? $operation;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(Operation $operation, string $eventType, OperationAuthor $author, array $payload = []): void
    {
        AuditEvent::query()->create([
            'operation_id' => $operation->id,
            'event_type' => $eventType,
            'actor_type' => $author->type,
            'actor_id' => $author->id,
            'actor_origin' => $author->origin,
            'payload' => InputRedactor::redact($payload),
        ]);
    }

    private function broadcast(Operation $operation): void
    {
        event(OperationUpdated::fromOperation($operation));
    }

    /**
     * Derive a flat set of ChangeProposal rows from an operation's already
     * redacted metadata, one level deep. Domain-specific services built on
     * top of this one (e.g. Task 8's ConfigChangeService) may add richer,
     * schema-aware proposals of their own.
     *
     * @param  array<string, mixed>  $redactedInput
     */
    private function recordChangeProposals(Operation $operation, array $redactedInput): void
    {
        foreach ($redactedInput as $field => $value) {
            if (is_array($value)) {
                foreach ($value as $nestedField => $nestedValue) {
                    $this->createChangeProposal($operation, "{$field}.{$nestedField}", $nestedValue);
                }

                continue;
            }

            $this->createChangeProposal($operation, (string) $field, $value);
        }
    }

    private function createChangeProposal(Operation $operation, string $field, mixed $value): void
    {
        ChangeProposal::query()->create([
            'operation_id' => $operation->id,
            'field' => $field,
            'summary' => "Proposed change to {$field}",
            'after' => is_scalar($value) ? (string) $value : json_encode($value),
        ]);
    }
}
