<?php

namespace App\Console;

use App\Console\Exceptions\CommandNotSafe;
use App\Models\Operation;
use App\Models\RconCommandPayload;
use App\Models\User;
use App\Operations\OperationAuthor;
use App\Operations\OperationRequest;
use App\Operations\OperationRisk;
use App\Operations\OperationService;

/**
 * Turns a raw console command string into a proposed (or, for safe
 * predefined actions, proposed + approved + executed) Operation, applying
 * CommandPolicy classification and secret-command redaction BEFORE
 * anything is persisted or ever reaches App\Operations\OperationService.
 * This is the one place a console command's real, unredacted text is
 * handed to the operation lifecycle — everywhere else (Operation::target,
 * ::redacted_input, ChangeProposal, AuditEvent, the realtime broadcast)
 * sees only a category and a redacted display value for secret-shaped
 * input (see App\Models\RconCommandPayload).
 *
 * Task 10's ambiguity resolution #4: Elevated commands stop at
 * proposeCommand() — a human must separately call
 * OperationService::approve() (a fresh approval) before ::execute() can
 * ever reach App\Operations\Handlers\RconCommandHandler. Safe predefined
 * actions may take the "lighter path", runSafeCommand(): propose,
 * immediately self-approve as the given (real, authenticated) human, and
 * execute — one call, but still fully audited and still going through the
 * exact same OperationService machinery as every other operation, just
 * without a separate human confirmation round-trip. There is no
 * system-auto-approve path: OperationService::approve() only ever accepts
 * a genuine App\Models\User (Task 5), so "lighter" means no extra click,
 * not no human involved.
 */
class RconCommandService
{
    public function __construct(
        private readonly OperationService $operations,
        private readonly CommandPolicy $policy,
    ) {}

    /**
     * Propose a console command. Never executes anything (matches
     * OperationService::propose()'s own contract). Persists only a
     * redacted display value when the command is secret-shaped, stashing
     * the real text in RconCommandPayload for RconCommandHandler to read
     * back later, once approved.
     */
    public function proposeCommand(string $rawCommand, OperationAuthor $author): Operation
    {
        $risk = $this->policy->classify($rawCommand) === CommandRisk::Safe
            ? OperationRisk::Standard
            : OperationRisk::Elevated;

        $isSecret = $this->policy->looksLikeSecret($rawCommand);

        $displayCommand = $isSecret
            ? $this->policy->redactedDisplay($rawCommand)
            : $this->policy->normalize($rawCommand);

        $operation = $this->operations->propose(
            OperationRequest::rconCommand($displayCommand, $risk),
            $author,
        );

        if ($isSecret) {
            RconCommandPayload::query()->create([
                'operation_id' => $operation->id,
                'command' => $rawCommand,
            ]);
        }

        return $operation;
    }

    /**
     * The "lighter path" for safe predefined actions. Refuses outright —
     * never proposing anything at all — for any command CommandPolicy
     * does not classify as Safe, so an Elevated (or unrecognized) command
     * can never reach RconCommandHandler by this route; it must always go
     * through proposeCommand() plus a separate OperationService::approve().
     */
    public function runSafeCommand(string $rawCommand, User $actor): Operation
    {
        if ($this->policy->classify($rawCommand) !== CommandRisk::Safe) {
            throw new CommandNotSafe;
        }

        $operation = $this->proposeCommand($rawCommand, OperationAuthor::user($actor->getKey()));

        $this->operations->approve($operation->id, $actor);

        return $this->operations->execute($operation->id);
    }
}
