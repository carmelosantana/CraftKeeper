<?php

namespace App\Ai\Tools;

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Config\Exceptions\ConfigConflict;
use App\Operations\OperationAuthor;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Throwable;

/**
 * The ONLY way the AI agent can affect configuration: calls
 * App\Config\ConfigChangeService::propose(), which ONLY EVER creates a
 * Proposed App\Models\Operation — it has no approve()/execute() method at
 * all (see its own class docblock). The Operation this tool creates is
 * authored by App\Operations\OperationAuthor::ai($sessionId), and
 * App\Operations\OperationService::approve()/reject() accept only a real,
 * authenticated App\Models\User — never an OperationAuthor — so there is
 * no code path from this tool (or anything this tool calls) to an
 * approved or executed mutation. A human must separately approve the
 * resulting proposal through the normal configuration review UI
 * (resources/js/features/config/DiffReview.tsx via
 * App\Http\Controllers\ConfigController::approve()) exactly like every
 * other configuration change in CraftKeeper.
 *
 * `path`/`base_sha256` route through the same MinecraftPath containment
 * boundary and optimistic-concurrency conflict check every other config
 * change already goes through (App\Config\ConfigChangeService::propose());
 * a stale or forged base hash is rejected, never silently overwritten.
 */
final class ProposeConfigChangeTool
{
    public static function make(string $sessionId): ToolInterface
    {
        return new Tool(
            name: 'propose_config_change',
            description: 'Propose a change to one config file. This NEVER applies anything — it only creates a proposal a human must separately review and approve before it takes effect. Use read_config first to get the current base_sha256.',
            parameters: [
                new StringParameter(name: 'path', description: 'The Minecraft-relative config file path to change.', required: true, maxLength: 512),
                new StringParameter(name: 'base_sha256', description: 'The sha256 returned by read_config for this file.', required: true, maxLength: 64),
                new ArrayParameter(
                    name: 'changes',
                    description: 'The list of field edits to propose.',
                    required: true,
                    items: new ObjectParameter(
                        name: 'change',
                        description: 'One field edit.',
                        properties: [
                            new StringParameter(name: 'path', description: 'The dotted field path (or literal key) to change.', required: true, maxLength: 255),
                            new EnumParameter(name: 'kind', description: 'The kind of edit.', values: ['replace', 'add', 'remove'], required: true),
                            new StringParameter(name: 'value', description: 'The new value, as a string. Omit for "remove".', required: false, maxLength: 4096),
                        ],
                    ),
                ),
                new StringParameter(name: 'summary', description: 'A short, human-readable explanation of why this change is being proposed.', required: false, maxLength: 500),
            ],
            callback: function (array $input) use ($sessionId): ToolResult {
                try {
                    $changes = array_values(array_map(function (array $change): ConfigChange {
                        return match ($change['kind']) {
                            'replace' => ConfigChange::replace($change['path'], $change['value'] ?? null),
                            'add' => ConfigChange::add($change['path'], $change['value'] ?? null),
                            'remove' => ConfigChange::remove($change['path']),
                            default => throw new \InvalidArgumentException('Unknown change kind: '.$change['kind']),
                        };
                    }, $input['changes']));
                } catch (Throwable $e) {
                    return ToolResult::error('Invalid changes: '.$e->getMessage());
                }

                $request = new ConfigChangeRequest($input['path'], $input['base_sha256'], $changes);

                try {
                    $operation = app(ConfigChangeService::class)->propose($request, OperationAuthor::ai($sessionId));
                } catch (ConfigConflict) {
                    return ToolResult::error('The file changed since it was last read. Call read_config again before proposing another change.');
                } catch (Throwable $e) {
                    return ToolResult::error('Unable to propose that change: '.$e->getMessage());
                }

                return ToolResult::json([
                    'operation_id' => $operation->id,
                    'path' => $input['path'],
                    'status' => $operation->status->value,
                    'risk' => $operation->risk->value,
                    'message' => 'Proposed. A human must review and approve this change before anything is written — it has not been applied.',
                ]);
            },
        );
    }
}
