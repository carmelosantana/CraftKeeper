<?php

namespace App\Operations\Handlers;

use App\Console\CommandPolicy;
use App\Console\Exceptions\InvalidRconPacket;
use App\Console\Exceptions\RconAuthFailed;
use App\Console\Exceptions\RconCommandTooLarge;
use App\Console\Exceptions\RconConnectionClosed;
use App\Console\Exceptions\RconException;
use App\Console\Exceptions\RconResponseTooLarge;
use App\Console\Exceptions\RconTimeout;
use App\Console\RconClient;
use App\Console\RconCommand;
use App\Models\AuditEvent;
use App\Models\Operation;
use App\Models\RconCommandPayload;
use App\Operations\OperationActorType;
use App\Operations\OperationHandler;
use App\Operations\OperationResult;
use App\Operations\OperationType;

/**
 * The OperationHandler for OperationType::RconCommand — registered on
 * App\Operations\OperationHandlerRegistry via the `operation.handler`
 * container tag (App\Providers\AppServiceProvider), per Task 5's
 * established extension convention.
 *
 * Only ever invoked by App\Operations\OperationService::execute(), which
 * structurally guarantees this runs for an Approved -> Running operation
 * and no other state — there is no code path in this class that sends a
 * command over RCON for an operation that was not actually approved.
 * classify() is also re-derived here from the operation's real command
 * text (never the persisted `risk` column alone) as defense in depth: the
 * risk this handler records on its own audit event always reflects what
 * CommandPolicy concludes about the exact bytes about to be sent, not a
 * value that could have drifted stale between propose() and execute().
 *
 * OperationResult::message is always a generic, templated string (the
 * command's category, never its raw text or the server's raw response
 * body) — Task 5's watch item that a handler's message must never carry a
 * secret value, and Operation.outcome (sourced from `message`) IS part of
 * the OperationUpdated broadcast allow-list, unlike `output`.
 */
final class RconCommandHandler implements OperationHandler
{
    public function __construct(
        private readonly RconClient $client,
        private readonly CommandPolicy $policy,
    ) {}

    public function supports(OperationType $type): bool
    {
        return $type === OperationType::RconCommand;
    }

    public function execute(Operation $operation): OperationResult
    {
        try {
            return $this->doExecute($operation);
        } finally {
            // The raw command (if this operation ever had one stashed —
            // only secret-shaped commands do) can never legitimately be
            // needed again once execute() has run, success or failure.
            RconCommandPayload::deleteForOperation($operation->id);
        }
    }

    public function rollback(Operation $operation): OperationResult
    {
        return OperationResult::failure(
            'rcon.command_not_rollbackable',
            'An RCON command cannot be rolled back automatically.',
        );
    }

    private function doExecute(Operation $operation): OperationResult
    {
        $rawCommand = $this->resolveRawCommand($operation);

        if ($rawCommand === null) {
            return OperationResult::failure(
                'rcon.payload_missing',
                'No command payload was found for this operation.',
            );
        }

        $risk = $this->policy->classify($rawCommand);
        $category = $this->policy->category($rawCommand);

        try {
            $command = RconCommand::from($rawCommand);
        } catch (InvalidRconPacket|RconCommandTooLarge) {
            return OperationResult::failure(
                'rcon.invalid_command',
                sprintf('The "%s" command could not be sent to the server.', $category),
            );
        }

        try {
            $response = $this->client->execute($command);
        } catch (RconException $e) {
            return OperationResult::failure(
                $this->errorCodeFor($e),
                sprintf('The "%s" command could not be completed.', $category),
            );
        }

        AuditEvent::query()->create([
            'operation_id' => $operation->id,
            'event_type' => 'rcon.command_executed',
            'actor_type' => OperationActorType::System,
            'actor_id' => null,
            'actor_origin' => 'system',
            'payload' => [
                'category' => $category,
                'risk' => $risk->value,
            ],
        ]);

        return OperationResult::success(
            sprintf('Executed the "%s" command.', $category),
            ['response' => $response->body],
        );
    }

    /**
     * The real command text needed to execute: from RconCommandPayload
     * for a secret-shaped command, or straight from the operation's own
     * (unredacted-but-non-secret) metadata otherwise. See
     * App\Console\RconCommandService::proposeCommand().
     */
    private function resolveRawCommand(Operation $operation): ?string
    {
        $payload = RconCommandPayload::query()->where('operation_id', $operation->id)->first();

        if ($payload instanceof RconCommandPayload) {
            return $payload->command;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $operation->redacted_input ?? [];

        return is_string($metadata['command'] ?? null) ? $metadata['command'] : null;
    }

    private function errorCodeFor(RconException $e): string
    {
        return match (true) {
            $e instanceof RconAuthFailed => 'rcon.auth_failed',
            $e instanceof RconTimeout => 'rcon.timeout',
            $e instanceof RconResponseTooLarge => 'rcon.response_too_large',
            $e instanceof RconConnectionClosed => 'rcon.connection_closed',
            $e instanceof InvalidRconPacket => 'rcon.invalid_packet',
            $e instanceof RconCommandTooLarge => 'rcon.command_too_large',
            default => 'rcon.unknown_error',
        };
    }
}
