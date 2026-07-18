<?php

namespace App\Mcp\Tools;

use App\Config\ConfigChange;
use App\Config\ConfigChangeRequest;
use App\Config\ConfigChangeService;
use App\Config\Exceptions\ConfigConflict;
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
 * The ONLY way an MCP client can affect configuration: calls
 * App\Config\ConfigChangeService::propose(), which ONLY EVER creates a
 * Proposed App\Models\Operation — see that service's own class docblock.
 * The Operation this tool creates is authored by
 * App\Operations\OperationAuthor::mcp($grant->oauth_client_id), and
 * App\Operations\OperationService::approve()/reject() only ever accept a
 * real, authenticated App\Models\User (never an OperationAuthor) — so
 * there is no code path from this tool, or anything it calls, to an
 * approved or executed mutation. A human must separately approve the
 * resulting proposal through the normal configuration review UI, exactly
 * like every other config change in CraftKeeper.
 *
 * Requires the `config:propose` scope (App\Policies\McpGrantPolicy,
 * enforced centrally by App\Mcp\Support\McpGuard BEFORE this tool's own
 * logic ever runs — a missing/revoked/expired grant never reaches the
 * code below). `path`/`expected_sha256` route through the SAME
 * MinecraftPath containment boundary and optimistic-concurrency conflict
 * check (App\Config\Exceptions\ConfigConflict) every other config change
 * already goes through — a stale or forged base hash is rejected, never
 * silently overwritten.
 */
#[Description('Propose a change to one CraftKeeper-managed config file. This NEVER writes anything — it only creates a proposal a human must separately review and approve before it takes effect. Read the config file resource first to get the current sha256.')]
class ProposeConfigChange extends Tool
{
    protected string $name = 'propose_config_change';

    public function handle(Request $request, McpGuard $guard, ConfigChangeService $changes): Response
    {
        // Audited arguments deliberately carry only the FIELD PATHS being
        // changed, never `changes[].value` itself: App\Operations\
        // InputRedactor::redact() (which App\Mcp\Support\McpGuard applies
        // to every audited argument set) only redacts by ARRAY KEY name —
        // a value like `changes.0.value` sits under the generic keys
        // "changes"/"value", never a key InputRedactor's pattern matches,
        // regardless of what the value itself contains (e.g. a new
        // rcon.password). Mirrors App\Config\ConfigChangeService's own
        // Operation::redacted_input, which likewise only ever stores
        // `changed_fields` (paths), never raw values — see
        // ConfigChangeService::storeRawChanges()'s docblock for why a
        // generic redaction pass is not trusted with real values.
        $auditArguments = [
            'path' => $request->get('path'),
            'expected_sha256' => $request->get('expected_sha256'),
            'changed_fields' => collect((array) $request->get('changes', []))
                ->map(fn ($change) => is_array($change) ? ($change['path'] ?? null) : null)
                ->filter()
                ->values()
                ->all(),
        ];

        return $guard->run('tool', $this->name(), ApiScope::ConfigPropose->value, $auditArguments, function (McpGrant $grant) use ($request, $changes) {
            $data = $request->validate([
                'path' => ['required', 'string', 'max:1024'],
                'expected_sha256' => ['required', 'string', 'size:64'],
                'changes' => ['required', 'array', 'min:1'],
                'changes.*.path' => ['required', 'string', 'max:255'],
                'changes.*.kind' => ['nullable', 'in:replace,add,remove'],
                'changes.*.value' => ['nullable'],
            ]);

            $changeObjects = array_values(array_map(function (array $change): ConfigChange {
                $kind = $change['kind'] ?? 'replace';

                return match ($kind) {
                    'add' => ConfigChange::add($change['path'], $change['value'] ?? null),
                    'remove' => ConfigChange::remove($change['path']),
                    default => ConfigChange::replace($change['path'], $change['value'] ?? null),
                };
            }, $data['changes']));

            $changeRequest = new ConfigChangeRequest($data['path'], $data['expected_sha256'], $changeObjects);

            try {
                $operation = $changes->propose($changeRequest, OperationAuthor::mcp($grant->oauth_client_id));
            } catch (ConfigConflict $e) {
                return Response::error('The file changed since it was last read; re-read the config resource before proposing another change: '.$e->getMessage());
            } catch (Throwable $e) {
                return Response::error('Unable to propose that change: '.$e->getMessage());
            }

            return Response::json([
                'id' => $operation->id,
                'status' => $operation->status->value,
                'path' => $data['path'],
                'risk' => $operation->risk->value,
                'message' => 'Proposed. A human must review and approve this change in CraftKeeper before anything is written — it has not been applied.',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The Minecraft-relative config file path to change.')->required(),
            'expected_sha256' => $schema->string()->description('The sha256 the caller believes the file currently has (read it via the config resource first).')->required(),
            'changes' => $schema->array()
                ->items($schema->object([
                    'path' => $schema->string()->description('The dotted field path (or literal key) to change.')->required(),
                    'kind' => $schema->string()->enum(['replace', 'add', 'remove'])->description('The kind of edit. Defaults to "replace" when omitted.'),
                    'value' => $schema->string()->description('The new value. Omit for "remove".'),
                ]))
                ->description('The field edits to propose.')
                ->required(),
        ];
    }
}
