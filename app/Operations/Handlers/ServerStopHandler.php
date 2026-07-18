<?php

namespace App\Operations\Handlers;

use App\Console\Exceptions\InvalidRconPacket;
use App\Console\Exceptions\RconAuthFailed;
use App\Console\Exceptions\RconConnectionClosed;
use App\Console\Exceptions\RconException;
use App\Console\Exceptions\RconResponseTooLarge;
use App\Console\Exceptions\RconTimeout;
use App\Console\RconClient;
use App\Console\RconCommand;
use App\Models\Operation;
use App\Operations\OperationHandler;
use App\Operations\OperationResult;
use App\Operations\OperationType;

/**
 * The OperationHandler for OperationType::ServerStop — registered on
 * App\Operations\OperationHandlerRegistry via the `operation.handler`
 * container tag, per Task 5's extension convention.
 *
 * Graceful stop, per Task 10's ambiguity resolution #5: sends
 * "save-all flush" THEN "stop" over RCON, strictly in that order (each as
 * its own RconClient::execute() call — the ordering is what the world can
 * observe, not a shared connection), then reports success with the exact
 * status text "Waiting for the Minecraft container restart policy." —
 * CraftKeeper NEVER shells out to `docker`, touches the Docker socket, or
 * calls any container API; it relies solely on the container's own
 * restart policy to bring the server back. Grep this file (and its test)
 * for "docker" to confirm: there is no such reference anywhere in it.
 *
 * The health-poll loop this status text promises ("polls RCON until it
 * becomes unavailable and then healthy again") is explicitly out of scope
 * here — Task 11's surface, per the brief — so this handler's coupling to
 * it is deliberately nothing at all: execute() returns as soon as the
 * stop sequence has been sent, it does not block waiting for the server
 * to actually go down or come back.
 */
final class ServerStopHandler implements OperationHandler
{
    public function __construct(
        private readonly RconClient $client,
    ) {}

    public function supports(OperationType $type): bool
    {
        return $type === OperationType::ServerStop;
    }

    public function execute(Operation $operation): OperationResult
    {
        try {
            $this->client->execute(RconCommand::from('save-all flush'));
            $this->client->execute(RconCommand::from('stop'));
        } catch (RconException $e) {
            return OperationResult::failure(
                $this->errorCodeFor($e),
                'Could not send the graceful stop sequence over RCON.',
            );
        }

        return OperationResult::success('Waiting for the Minecraft container restart policy.');
    }

    public function rollback(Operation $operation): OperationResult
    {
        return OperationResult::failure(
            'server.stop_not_rollbackable',
            'A server stop cannot be rolled back.',
        );
    }

    private function errorCodeFor(RconException $e): string
    {
        return match (true) {
            $e instanceof RconAuthFailed => 'rcon.auth_failed',
            $e instanceof RconTimeout => 'rcon.timeout',
            $e instanceof RconResponseTooLarge => 'rcon.response_too_large',
            $e instanceof RconConnectionClosed => 'rcon.connection_closed',
            $e instanceof InvalidRconPacket => 'rcon.invalid_packet',
            default => 'rcon.unknown_error',
        };
    }
}
