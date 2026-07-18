<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DiagnoseServer;
use App\Mcp\Resources\ActivityResource;
use App\Mcp\Resources\ConfigFileResource;
use App\Mcp\Resources\ConfigResource;
use App\Mcp\Resources\PluginResource;
use App\Mcp\Resources\ServerStatusResource;
use App\Mcp\Tools\ProposeConfigChange;
use App\Mcp\Tools\ProposePluginOperation;
use App\Mcp\Tools\RunSafeRcon;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource as McpResource;
use Laravel\Mcp\Server\Tool;

/**
 * CraftKeeper's guarded MCP server — POST /mcp/craftkeeper (routes/mcp.php).
 *
 * SECURITY-CRITICAL, Task 18's crux: the tool set below is EXACTLY these
 * three propose-only tools. There is deliberately NO approve_operation
 * tool, NO raw-filesystem tool, NO arbitrary source editor, NO
 * secret-reader tool, NO shell/Docker tool, and NO elevated-RCON tool
 * anywhere in this class or this application — an MCP client can PROPOSE
 * a mutation but can NEVER approve one (App\Operations\OperationService::
 * approve()/reject() only ever accept a real, authenticated
 * App\Models\User; App\Operations\OperationAuthor::mcp() cannot satisfy
 * that parameter type — see that method's own docblock). A proposal an
 * MCP client creates stays App\Operations\OperationStatus::Proposed until
 * a HUMAN approves it through the ordinary web review UI — separation of
 * duties: an MCP client cannot approve proposals it, or anyone else,
 * created. See docs/architecture/decisions.md's Task 18 entry for the
 * full reconciliation.
 *
 * Every tool and resource enforces its OWN scope via
 * App\Policies\McpGrantPolicy, centrally through App\Mcp\Support\McpGuard
 * (every primitive's `handle()` calls `$guard->run(...)` before touching
 * any domain service) — see each primitive's own class docblock for its
 * required scope. `auth:passport` at the route level additionally blocks
 * every anonymous request before ANY JSON-RPC method (including
 * tools/list, resources/list, prompts/list) is dispatched.
 */
#[Name('CraftKeeper')]
#[Version('1.0.0')]
#[Instructions(
    'CraftKeeper exposes bounded, redacted read-only resources (server status, config inventory/content, '.
    'plugin inventory, recent activity) and three propose-only tools (config, plugin, and safe RCON changes). '.
    'Every write is a human-reviewable proposal only — nothing this server does can execute, apply, or approve '.
    'a mutation. Every call is scoped to the connected OAuth grant and fully audited.'
)]
class CraftKeeperServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ProposeConfigChange::class,
        ProposePluginOperation::class,
        RunSafeRcon::class,
    ];

    /**
     * @var array<int, class-string<McpResource>>
     */
    protected array $resources = [
        ServerStatusResource::class,
        ConfigResource::class,
        ConfigFileResource::class,
        PluginResource::class,
        ActivityResource::class,
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        DiagnoseServer::class,
    ];
}
