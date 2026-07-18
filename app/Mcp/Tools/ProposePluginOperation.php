<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpGuard;
use App\Models\McpGrant;
use App\Models\PluginInstallation;
use App\Operations\OperationAuthor;
use App\Plugins\PluginInventoryService;
use App\Plugins\PluginLifecycleService;
use App\Support\ApiScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * The ONLY way an MCP client can affect installed plugins: proposes a
 * disable or remove of an ALREADY-INSTALLED plugin via
 * App\Plugins\PluginLifecycleService::proposeDisable()/proposeRemove() —
 * which, like App\Config\ConfigChangeService::propose(), only ever creates
 * a Proposed App\Models\Operation, never executes anything. Deliberately
 * does NOT expose install/update: those require resolving a catalog
 * release and downloading/inspecting a real artifact over the network
 * (App\Plugins\PluginLifecycleService::proposeInstall()/proposeUpdate()),
 * which would hand an MCP client a network-fetch surface this guarded
 * tool set intentionally excludes — mirroring Task 17's identical
 * `/api/v1/plugins` scope decision (see that task's
 * docs/architecture/decisions.md entry).
 *
 * Authored by App\Operations\OperationAuthor::mcp($grant->oauth_client_id);
 * App\Operations\OperationService::approve()/reject() only ever accept a
 * real App\Models\User, so nothing this tool calls can ever approve or
 * reject anything. Requires the `plugins:manage` scope.
 */
#[Description('Propose disabling or removing an installed plugin. This NEVER changes anything on disk — it only creates a proposal a human must separately review and approve. Installing or updating a plugin is not available through this tool.')]
class ProposePluginOperation extends Tool
{
    protected string $name = 'propose_plugin_operation';

    public function handle(Request $request, McpGuard $guard, PluginInventoryService $inventory, PluginLifecycleService $lifecycle): Response
    {
        return $guard->run('tool', $this->name(), ApiScope::PluginsManage->value, $request->all(), function (McpGrant $grant) use ($request, $inventory, $lifecycle) {
            $data = $request->validate([
                'filename' => ['required', 'string', 'max:255'],
                'operation' => ['required', 'in:disable,remove'],
            ]);

            $inventory->reconcile();

            $installation = PluginInstallation::query()
                ->where('relative_path', 'plugins/'.$data['filename'])
                ->first();

            if ($installation === null) {
                return Response::error("No installed plugin found at [plugins/{$data['filename']}].");
            }

            $author = OperationAuthor::mcp($grant->oauth_client_id);

            try {
                $operation = $data['operation'] === 'disable'
                    ? $lifecycle->proposeDisable($installation, $author)
                    : $lifecycle->proposeRemove($installation, $author);
            } catch (Throwable $e) {
                return Response::error('Unable to propose that plugin operation: '.$e->getMessage());
            }

            return Response::json([
                'id' => $operation->id,
                'status' => $operation->status->value,
                'filename' => $data['filename'],
                'operation' => $data['operation'],
                'risk' => $operation->risk->value,
                'message' => 'Proposed. A human must review and approve this in CraftKeeper before it takes effect — nothing has changed yet.',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filename' => $schema->string()->description('The installed plugin JAR filename (e.g. "EssentialsX.jar"), relative to the plugins directory.')->required(),
            'operation' => $schema->string()->enum(['disable', 'remove'])->description('Which lifecycle operation to propose.')->required(),
        ];
    }
}
