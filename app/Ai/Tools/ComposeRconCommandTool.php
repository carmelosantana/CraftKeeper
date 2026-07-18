<?php

namespace App\Ai\Tools;

use App\Console\CommandPolicy;
use App\Console\CommandRisk;
use App\Console\RconCommandService;
use App\Operations\OperationAuthor;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Throwable;

/**
 * The ONLY way the AI agent can affect the running server via RCON. Never
 * executes anything: classifies the command's risk with the SAME
 * App\Console\CommandPolicy every human-composed command goes through
 * (App\Http\Controllers\ConsoleController::compose()), then calls
 * App\Console\RconCommandService::proposeCommand() — NEVER
 * ::runSafeCommand(), which requires a real App\Models\User and is
 * reserved for human-triggered predefined-safe buttons — so the resulting
 * Operation is always merely Proposed, regardless of how safe the
 * command's classified risk turns out to be. A human must separately
 * approve it from the console's normal operation approval panel
 * (resources/js/features/console/CommandComposer.tsx via
 * App\Http\Controllers\ConsoleController::approve()) before
 * App\Operations\Handlers\RconCommandHandler ever executes it. Like
 * App\Ai\Tools\ProposeConfigChangeTool, the Operation is authored by
 * App\Operations\OperationAuthor::ai($sessionId), which
 * App\Operations\OperationService::approve()/reject() structurally cannot
 * accept — only a real App\Models\User can.
 */
final class ComposeRconCommandTool
{
    public static function make(string $sessionId): ToolInterface
    {
        return new Tool(
            name: 'compose_rcon_command',
            description: 'Compose and propose a console (RCON) command. This NEVER runs the command — it classifies its risk and creates a proposal a human must separately approve before it reaches the server.',
            parameters: [
                new StringParameter(name: 'command', description: 'The exact console command text.', required: true, maxLength: 500),
                new StringParameter(name: 'explanation', description: "A short, human-readable explanation of what this command does and why it's being proposed.", required: true, maxLength: 500),
            ],
            callback: function (array $input) use ($sessionId): ToolResult {
                $policy = app(CommandPolicy::class);
                $risk = $policy->classify($input['command']);

                try {
                    $operation = app(RconCommandService::class)->proposeCommand($input['command'], OperationAuthor::ai($sessionId));
                } catch (Throwable $e) {
                    return ToolResult::error('Unable to propose that command: '.$e->getMessage());
                }

                return ToolResult::json([
                    'operation_id' => $operation->id,
                    'status' => $operation->status->value,
                    'command' => $policy->redactedDisplay($input['command']),
                    'explanation' => $input['explanation'],
                    'risk' => $risk->value,
                    'consequence' => self::consequenceFor($risk),
                    'message' => 'Proposed. A human must approve this command in the console before it reaches the server — it has not run.',
                ]);
            },
        );
    }

    private static function consequenceFor(CommandRisk $risk): string
    {
        return $risk === CommandRisk::Safe
            ? 'This command is on the read-only/announcement safe list. Even so, an AI-proposed command always requires human approval before it runs.'
            : 'This command is not on the safe list and may change server or player state. A human must review and approve it before it runs.';
    }
}
