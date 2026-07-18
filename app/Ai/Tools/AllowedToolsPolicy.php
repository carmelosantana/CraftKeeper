<?php

namespace App\Ai\Tools;

use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;

/**
 * Belt-and-suspenders defense in depth alongside App\Ai\AssistantAgent's
 * closed tools() list: carmelosantana/php-agents already resolves tool
 * calls against a name-indexed map built ONLY from tools() + DoneTool
 * (see vendor/carmelosantana/php-agents/src/Agent/AbstractAgent.php's
 * collectAllToolsIndexed()), so a model can never invoke anything not
 * explicitly registered — an unknown name throws ToolNotFoundException
 * internally, which the agent loop turns into a denied ToolResult rather
 * than ever executing anything or crashing the run. This policy is a
 * second, independent gate checked BEFORE that resolution even happens:
 * even if a future change to this file's tool registration ever grew to
 * include something broader (a toolkit, say), this allowlist alone still
 * decides what may execute.
 *
 * `done` is the vendor package's own built-in completion signal (carries
 * only the model's own final text, no I/O) — see DoneTool's docblock —
 * and is included here so it is never denied.
 *
 * There is deliberately no "approve", "execute", "delete", or any
 * filesystem/RCON-direct tool name on this list, or anywhere in this
 * application: see App\Ai\Tools\ReadConfigTool,
 * App\Ai\Tools\ProposeConfigChangeTool, and
 * App\Ai\Tools\ComposeRconCommandTool's own docblocks for why none of
 * them can approve or execute a mutation — only a real, authenticated
 * human web request can (App\Operations\OperationService::approve()/
 * reject(), which accept a real App\Models\User, never an
 * App\Operations\OperationAuthor).
 */
final class AllowedToolsPolicy implements ToolExecutionPolicyInterface
{
    /**
     * @var list<string>
     */
    private const ALLOWED = [
        'read_config',
        'propose_config_change',
        'compose_rcon_command',
        'done',
    ];

    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        return in_array($toolName, self::ALLOWED, true)
            ? true
            : "Tool \"{$toolName}\" is not permitted for the AI assistant.";
    }
}
