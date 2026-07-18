<?php

use App\Ai\Tools\ComposeRconCommandTool;
use App\Ai\Tools\ProposeConfigChangeTool;
use App\Ai\Tools\ReadConfigTool;
use App\Models\Operation;
use App\Operations\OperationActorType;
use App\Operations\OperationStatus;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

/*
|--------------------------------------------------------------------------
| ReadConfigTool: bounded, redacted, read-only
|--------------------------------------------------------------------------
*/

it('returns a redacted excerpt and never a raw secret value', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\nrcon.password=super-secret-value\n");

    $tool = ReadConfigTool::make();
    $result = $tool->execute(['path' => 'server.properties']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->not->toContain('super-secret-value')
        ->and($result->content)->toContain('motd');
});

it('refuses to read outside the Minecraft root', function () {
    $tool = ReadConfigTool::make();
    $result = $tool->execute(['path' => '../outside.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

/*
|--------------------------------------------------------------------------
| ProposeConfigChangeTool: propose only, never approve
|--------------------------------------------------------------------------
*/

it('creates a Proposed operation authored by the AI, which cannot be approved by this tool', function () {
    $contents = "motd=hi\nallow-flight=false\n";
    file_put_contents($this->minecraftRoot.'/server.properties', $contents);

    $tool = ProposeConfigChangeTool::make('session-123');
    $result = $tool->execute([
        'path' => 'server.properties',
        'base_sha256' => hash('sha256', $contents),
        'changes' => [
            ['path' => 'allow-flight', 'kind' => 'replace', 'value' => 'true'],
        ],
        'summary' => 'Enable flight for creative testing.',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $payload = json_decode($result->content, true);
    $operation = Operation::query()->findOrFail($payload['operation_id']);

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->author_type)->toBe(OperationActorType::Ai)
        ->and($operation->author_id)->toBe('session-123')
        ->and($operation->approved_at)->toBeNull();

    // ProposeConfigChangeTool exposes no way to approve — the tool class
    // itself has no approve()/execute() method, and the only class that
    // CAN transition an Operation (App\Operations\OperationService)
    // requires a real App\Models\User for approve()/reject(), which this
    // tool never has access to.
    expect(method_exists(ProposeConfigChangeTool::class, 'approve'))->toBeFalse();
});

it('reports a conflict instead of proposing when the base hash is stale', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");

    $tool = ProposeConfigChangeTool::make('session-123');
    $result = $tool->execute([
        'path' => 'server.properties',
        'base_sha256' => hash('sha256', 'stale-content'),
        'changes' => [['path' => 'motd', 'kind' => 'replace', 'value' => 'bye']],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('changed since it was last read');

    expect(Operation::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| ComposeRconCommandTool: risk + consequence, propose only
|--------------------------------------------------------------------------
*/

it('classifies risk, proposes (never runs) a command, and always requires human approval', function () {
    $tool = ComposeRconCommandTool::make('session-456');
    $result = $tool->execute([
        'command' => 'op griefer123',
        'explanation' => 'Requested by the operator to grant admin.',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $payload = json_decode($result->content, true);

    expect($payload['risk'])->toBe('elevated')
        ->and($payload['consequence'])->toBeString()->not->toBe('');

    $operation = Operation::query()->findOrFail($payload['operation_id']);

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->type->value)->toBe('rcon.command')
        ->and($operation->author_type)->toBe(OperationActorType::Ai);
});

it('still requires approval for a safe-classified command proposed by the AI', function () {
    $tool = ComposeRconCommandTool::make('session-456');
    $result = $tool->execute([
        'command' => 'list',
        'explanation' => 'Check who is online.',
    ]);

    $payload = json_decode($result->content, true);

    expect($payload['risk'])->toBe('safe');

    $operation = Operation::query()->findOrFail($payload['operation_id']);

    // Even a "safe" command proposed by the AI stops at Proposed — only
    // RconCommandService::runSafeCommand() (which requires a real
    // App\Models\User, never called by this tool) can skip straight to
    // execution, and this tool never calls it.
    expect($operation->status)->toBe(OperationStatus::Proposed);
});
