<?php

use App\Mcp\Prompts\DiagnoseServer;
use App\Mcp\Resources\ActivityResource;
use App\Mcp\Resources\ConfigFileResource;
use App\Mcp\Resources\ConfigResource;
use App\Mcp\Resources\PluginResource;
use App\Mcp\Resources\ServerStatusResource;
use App\Mcp\Servers\CraftKeeperServer;
use App\Mcp\Tools\ProposeConfigChange;
use App\Mcp\Tools\ProposePluginOperation;
use App\Mcp\Tools\RunSafeRcon;
use App\Models\User;
use App\Operations\OperationService;
use Laravel\Mcp\Server\Transport\FakeTransporter;

/**
 * Task 18's capability contract: App\Mcp\Servers\CraftKeeperServer's
 * registered tool/resource/prompt set is EXACTLY what this task's
 * ambiguity resolution #1 allows — nothing more. Pure reflection over the
 * class definition, no DB, no HTTP, no in-process invocation — the
 * closest thing to "run `php artisan mcp:inspector mcp/craftkeeper` and
 * diff the capability list" this suite can assert automatically. See the
 * task report for the actual manual `mcp:inspector` run.
 */
function serverProperty(string $property): array
{
    $reflection = new ReflectionClass(CraftKeeperServer::class);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);

    return $prop->getValue(new CraftKeeperServer(new FakeTransporter));
}

it('exposes EXACTLY these three propose-only tools — nothing more', function () {
    expect(serverProperty('tools'))->toBe([
        ProposeConfigChange::class,
        ProposePluginOperation::class,
        RunSafeRcon::class,
    ]);
});

it('names each tool with the exact snake_case name the brief specifies', function () {
    expect((new ProposeConfigChange)->name())->toBe('propose_config_change');
    expect((new ProposePluginOperation)->name())->toBe('propose_plugin_operation');
    expect((new RunSafeRcon)->name())->toBe('run_safe_rcon');
});

it('exposes EXACTLY these five bounded, read-only resources', function () {
    expect(serverProperty('resources'))->toBe([
        ServerStatusResource::class,
        ConfigResource::class,
        ConfigFileResource::class,
        PluginResource::class,
        ActivityResource::class,
    ]);
});

it('exposes EXACTLY one prompt', function () {
    expect(serverProperty('prompts'))->toBe([
        DiagnoseServer::class,
    ]);
});

it('has no approve/execute/apply/shell/docker/secret-reader/raw-filesystem tool class anywhere under app/Mcp', function () {
    $forbiddenNamePatterns = [
        'approve', 'execute_operation', 'apply_operation', 'run_command', 'shell',
        'docker', 'read_secret', 'get_secret', 'read_file', 'write_file', 'edit_file',
        'source_edit', 'raw_filesystem', 'elevated_rcon', 'admin_rcon',
    ];

    $files = array_merge(
        glob(app_path('Mcp/Tools/*.php')) ?: [],
        glob(app_path('Mcp/Resources/*.php')) ?: [],
    );

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $basename = strtolower(basename($file, '.php'));

        foreach ($forbiddenNamePatterns as $pattern) {
            expect(str_contains($basename, str_replace('_', '', $pattern)))->toBeFalse(
                "File [{$file}] matches a forbidden tool name pattern [{$pattern}]."
            );
        }
    }
});

it('no class under app/Mcp calls OperationService::approve() or ->approve( on anything', function () {
    $files = array_merge(
        glob(app_path('Mcp/Tools/*.php')) ?: [],
        glob(app_path('Mcp/Resources/*.php')) ?: [],
        glob(app_path('Mcp/Prompts/*.php')) ?: [],
        glob(app_path('Mcp/Servers/*.php')) ?: [],
        glob(app_path('Mcp/Support/*.php')) ?: [],
    );

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('->approve(');
    }
});

it('OperationService::approve() only ever accepts a real, authenticated App\Models\User — never an OperationAuthor', function () {
    $reflection = new ReflectionMethod(OperationService::class, 'approve');
    $parameters = $reflection->getParameters();

    expect($parameters)->toHaveCount(2)
        ->and($parameters[1]->getType()?->__toString())->toBe(User::class);
});

it('every propose-only tool authors its operation via OperationAuthor::mcp(), never OperationAuthor::user()', function () {
    foreach ([ProposeConfigChange::class, ProposePluginOperation::class, RunSafeRcon::class] as $toolClass) {
        $contents = (string) file_get_contents((new ReflectionClass($toolClass))->getFileName());
        expect($contents)->toContain('OperationAuthor::mcp(')
            ->and($contents)->not->toContain('OperationAuthor::user(');
    }
});

it('every tool schema resolves without error', function () {
    foreach ([new ProposeConfigChange, new ProposePluginOperation, new RunSafeRcon] as $tool) {
        $array = $tool->toArray();

        expect($array)->toHaveKey('name')
            ->and($array)->toHaveKey('inputSchema')
            ->and($array['inputSchema'])->toHaveKey('properties');
    }
});

it('every resource declares a craftkeeper:// URI (static or template)', function () {
    foreach ([new ServerStatusResource, new ConfigResource, new PluginResource, new ActivityResource] as $resource) {
        expect($resource->uri())->toStartWith('craftkeeper://');
    }

    expect((string) (new ConfigFileResource)->uriTemplate())->toStartWith('craftkeeper://');
});
