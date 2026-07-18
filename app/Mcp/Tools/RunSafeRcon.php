<?php

namespace App\Mcp\Tools;

use App\Console\CommandPolicy;
use App\Console\CommandRisk;
use App\Console\RconCommandService;
use App\Mcp\Support\McpGuard;
use App\Models\McpGrant;
use App\Operations\OperationAuthor;
use App\Support\ApiScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * The ONLY way an MCP client can affect the running server via RCON, and
 * ONLY for a command App\Console\CommandPolicy classifies as Safe (a
 * small, fixed, predefined allow-list — see that class's own docblock); a
 * command classified Elevated is refused outright, never proposed. Even
 * for a Safe command, this NEVER executes it: it calls
 * App\Console\RconCommandService::proposeCommand() — NEVER
 * ::runSafeCommand(), which requires a real, authenticated App\Models\User
 * and self-approves+executes in one call (reserved for a human clicking a
 * predefined-safe button in the Console UI). The resulting Operation is
 * therefore always merely Proposed, authored by
 * App\Operations\OperationAuthor::mcp($grant->oauth_client_id), which
 * App\Operations\OperationService::approve()/reject() structurally cannot
 * accept — only a real App\Models\User can. A human must separately
 * approve it from the console's normal operation approval panel before it
 * ever reaches the server.
 *
 * Requires the `rcon:safe` scope. There is no elevated-RCON tool, and no
 * scope this tool accepts can ever propose an Elevated-classified command.
 */
#[Description('Propose a console (RCON) command CLASSIFIED AS SAFE by CraftKeeper\'s command policy (e.g. "list", "say <message>"). This NEVER runs the command — it creates a proposal a human must separately approve before it reaches the server. A command not on the safe allow-list is refused outright, not proposed.')]
class RunSafeRcon extends Tool
{
    protected string $name = 'run_safe_rcon';

    public function handle(Request $request, McpGuard $guard, CommandPolicy $policy, RconCommandService $commands): Response
    {
        // Audited arguments deliberately carry $policy->redactedDisplay()'s
        // output, never the raw `command` value: App\Operations\
        // InputRedactor::redact() (which App\Mcp\Support\McpGuard applies
        // to every audited argument set) only redacts by ARRAY KEY name —
        // a secret-shaped command's text sits entirely under the generic
        // "command" key, which InputRedactor's pattern never matches,
        // regardless of what the value itself contains (e.g. "login
        // <password>"). This mirrors App\Mcp\Tools\ProposeConfigChange's
        // own audit-argument sanitization, and reuses the SAME
        // CommandPolicy::redactedDisplay() this tool already applies to
        // its own response (below) — a secret-shaped command is masked,
        // an ordinary safe command's text is preserved for a useful audit
        // trail. McpGuard::run() audits these arguments on the
        // denied/error path BEFORE the callback below ever runs, so the
        // sanitized set — not the real command — is what gets persisted
        // even when the command is refused as Elevated. Classification and
        // execution below still use the REAL, unredacted command text.
        $rawCommand = $request->get('command', '');
        $auditArguments = [
            'command' => $policy->redactedDisplay(is_string($rawCommand) ? $rawCommand : ''),
        ];

        return $guard->run('tool', $this->name(), ApiScope::RconSafe->value, $auditArguments, function (McpGrant $grant) use ($request, $policy, $commands) {
            $data = $request->validate([
                'command' => ['required', 'string', 'max:500'],
            ]);

            if ($policy->classify($data['command']) !== CommandRisk::Safe) {
                return Response::error('This command is not on CraftKeeper\'s safe allow-list. Only predefined safe commands (e.g. "list", "say <message>") may be proposed through this tool.');
            }

            $author = OperationAuthor::mcp($grant->oauth_client_id);

            try {
                $operation = $commands->proposeCommand($data['command'], $author);
            } catch (Throwable $e) {
                return Response::error('Unable to propose that command: '.$e->getMessage());
            }

            return Response::json([
                'id' => $operation->id,
                'status' => $operation->status->value,
                'command' => $policy->redactedDisplay($data['command']),
                'risk' => $operation->risk->value,
                'message' => 'Proposed. A human must approve this command in the console before it reaches the server — it has not run.',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()->description('The exact safe console command text (e.g. "list", "say Hello everyone").')->required(),
        ];
    }
}
