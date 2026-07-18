<?php

use App\Console\MinecraftRconClient;
use App\Console\RconClient;
use App\Console\RconCommand;
use App\Console\RconResponse;
use App\Models\ConsoleEntry;
use App\Models\Operation;
use App\Models\User;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\fixtures\rcon\FakeRconTransport;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

/**
 * A RconClient double that fails the test the instant it is asked to
 * execute anything — used to PROVE, at the HTTP boundary, that
 * compose()/propose() (and a rejected/never-approved operation) never
 * reach the transport. Bound over the container's real RconClient binding
 * (App\Providers\AppServiceProvider), which would otherwise try to open a
 * real socket.
 */
function bindPoisonedRconClient(): void
{
    app()->instance(RconClient::class, new class implements RconClient
    {
        public function execute(RconCommand $command): RconResponse
        {
            throw new RuntimeException('RconClient::execute() must never be called for this test — an elevated command reached the transport without approval.');
        }
    });
}

/**
 * Binds a real MinecraftRconClient wired to a FakeRconTransport that
 * answers ANY single-command exec with a successful, empty response —
 * enough for RconCommandHandler::execute() (one execute() call per
 * command) to succeed without ever opening a real socket.
 */
function bindFakeRconClient(string $body = ''): void
{
    $bytes = FakeRconTransport::packet(1, 2, '').FakeRconTransport::packet(2, 0, $body).FakeRconTransport::packet(3, 0, '');
    app()->instance(RconClient::class, new MinecraftRconClient(FakeRconTransport::respondingWith($bytes)));
}

/*
|--------------------------------------------------------------------------
| Auth gate
|--------------------------------------------------------------------------
*/

it('requires authentication for every console route', function () {
    $this->get('/server/console')->assertRedirect('/login');
    $this->post('/server/console', ['command' => 'stop'])->assertRedirect('/login');
    $this->post('/server/console/propose', ['command' => 'stop'])->assertRedirect('/login');
});

/*
|--------------------------------------------------------------------------
| Step 1 (the brief's own test, at the HTTP/props layer): consequence
| review before an elevated command, no side effects from composing alone
|--------------------------------------------------------------------------
*/

it('classifies an elevated command as requiring approval and shows its consequence, without creating an Operation or touching RCON', function () {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console', ['command' => 'stop'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('server/Console')
            ->where('composePreview.risk', 'elevated')
            ->where('composePreview.requiresApproval', true)
            ->where('composePreview.consequence', 'Stops the Minecraft server.')
        );

    expect(Operation::query()->count())->toBe(0);
});

it('classifies a safe predefined command as not requiring approval', function () {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console', ['command' => 'list'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('composePreview.risk', 'safe')
            ->where('composePreview.requiresApproval', false)
            ->where('composePreview.consequence', 'Lists players currently online. Read-only.')
        );

    expect(Operation::query()->count())->toBe(0);
});

it('defaults an unrecognized command to Elevated, per CommandPolicy default-deny', function () {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console', ['command' => 'totally-unknown-command'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('composePreview.risk', 'elevated')
            ->where('composePreview.requiresApproval', true)
            ->where('composePreview.consequence', 'This command is not on the predefined safe list and may change server or player state.')
        );
});

it('shows named consequences for every elevated command the brief lists', function (string $command, string $consequence) {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console', ['command' => $command])
        ->assertInertia(fn (Assert $page) => $page
            ->where('composePreview.risk', 'elevated')
            ->where('composePreview.consequence', $consequence)
        );
})->with([
    ['stop', 'Stops the Minecraft server.'],
    ['op Steve', 'Grants a player operator (admin) privileges.'],
    ['deop Steve', "Revokes a player's operator (admin) privileges."],
    ['ban Steve griefing', 'Bans a player from the server, disconnecting them immediately.'],
    ['whitelist add Steve', 'Changes who is allowed to join the server.'],
    ['gamerule keepInventory true', 'Changes a server-wide game rule affecting every player.'],
    ['execute as Steve run say hi', 'Runs another command as a different entity or context — can perform any other elevated action.'],
]);

/*
|--------------------------------------------------------------------------
| propose(): still no RCON, but a real, reviewable Operation exists
|--------------------------------------------------------------------------
*/

it('proposes an elevated command as a real Proposed Operation without ever touching RCON', function () {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console/propose', ['command' => 'stop'])
        ->assertRedirect();

    $operation = Operation::query()->sole();

    expect($operation->status)->toBe(OperationStatus::Proposed)
        ->and($operation->type)->toBe(OperationType::ServerStop)
        ->and($operation->target)->toBe('server');
});

it('never persists a secret-shaped composed command as raw text on the proposed operation', function () {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console/propose', ['command' => 'login mySuperSecretPass123'])
        ->assertRedirect();

    $operation = Operation::query()->sole();

    expect($operation->target)->toBe('login ••••••')
        ->and($operation->target)->not->toContain('mySuperSecretPass123')
        ->and(json_encode($operation->redacted_input))->not->toContain('mySuperSecretPass123');
});

/*
|--------------------------------------------------------------------------
| approve(): the ONLY route that can lead to RCON — fresh approval only
|--------------------------------------------------------------------------
*/

it('only executes an elevated command after a fresh, separate approval — never at compose or propose time', function () {
    bindFakeRconClient('Made Steve a server operator');

    $this->actingAs($this->admin)->post('/server/console/propose', ['command' => 'op Steve']);
    $operation = Operation::query()->sole();

    expect($operation->status)->toBe(OperationStatus::Proposed);

    // Re-bind a poisoned client to prove NOTHING has reached RCON yet —
    // if propose() had somehow already executed, this would have already
    // thrown before we got here.
    bindPoisonedRconClient();
    expect(Operation::query()->sole()->status)->toBe(OperationStatus::Proposed);

    // Now install the real fake back and approve for real.
    bindFakeRconClient('Made Steve a server operator');

    $this->actingAs($this->admin)
        ->post("/server/console/operations/{$operation->id}/approve")
        ->assertRedirect();

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->approved_by_id)->toBe((string) $this->admin->id);
});

it('rejects an elevated command without ever touching RCON', function () {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)->post('/server/console/propose', ['command' => 'stop']);
    $operation = Operation::query()->sole();

    $this->actingAs($this->admin)
        ->post("/server/console/operations/{$operation->id}/reject")
        ->assertRedirect();

    expect($operation->refresh()->status)->toBe(OperationStatus::Rejected);
});

it('refuses to approve an operation that is not a pending console operation', function () {
    bindPoisonedRconClient();

    $configOperation = Operation::factory()->ofType(OperationType::ConfigApply)->create();

    $this->actingAs($this->admin)
        ->post("/server/console/operations/{$configOperation->id}/approve")
        ->assertNotFound();
});

it('refuses to approve an already-terminal console operation a second time', function () {
    bindFakeRconClient();

    $this->actingAs($this->admin)->post('/server/console/propose', ['command' => 'op Steve']);
    $operation = Operation::query()->sole();
    $this->actingAs($this->admin)->post("/server/console/operations/{$operation->id}/approve");

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);

    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post("/server/console/operations/{$operation->id}/approve")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Safe predefined actions: the lighter path, still audited, Safe-only
|--------------------------------------------------------------------------
*/

it('runs a predefined safe action end-to-end in one call, fully audited', function () {
    bindFakeRconClient('There are 0 of a max of 20 players online:');

    $this->actingAs($this->admin)
        ->post('/server/console/actions/list')
        ->assertRedirect();

    $operation = Operation::query()->sole();

    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->risk->value)->toBe('standard')
        ->and($operation->approved_by_id)->toBe((string) $this->admin->id);
});

it('appends the message for the "say" predefined action and still classifies Safe', function () {
    bindFakeRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console/actions/say', ['message' => 'Server restarting soon'])
        ->assertRedirect();

    $operation = Operation::query()->sole();
    expect($operation->target)->toBe('say Server restarting soon')
        ->and($operation->status)->toBe(OperationStatus::Succeeded);
});

it('runs a manually typed Safe command via the lighter path', function () {
    bindFakeRconClient('There are 0 of a max of 20 players online:');

    $this->actingAs($this->admin)
        ->post('/server/console/run', ['command' => 'list'])
        ->assertRedirect();

    $operation = Operation::query()->sole();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->target)->toBe('list');
});

it('refuses to run a manually typed Elevated command via the lighter path', function () {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console/run', ['command' => 'stop'])
        ->assertRedirect();

    expect(Operation::query()->count())->toBe(0);
});

it('404s for an unknown predefined action key rather than running arbitrary text', function () {
    bindPoisonedRconClient();

    $this->actingAs($this->admin)
        ->post('/server/console/actions/rm-rf-everything')
        ->assertNotFound();

    expect(Operation::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Degraded RCON never fabricates availability; file-based console feed
| stays usable regardless
|--------------------------------------------------------------------------
*/

it('reports RCON unavailable honestly, with a reason, when no sample has ever been recorded — never a fabricated available state', function () {
    $this->actingAs($this->admin)
        ->get('/server/console')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rcon.available', false)
            ->where('rcon.reason', 'No RCON sample has been recorded yet.')
        );
});

it('still returns the tailed console feed when RCON is unavailable', function () {
    ConsoleEntry::query()->create(['line' => '[00:00:01 INFO]: Server started', 'occurred_at' => now()]);

    $this->actingAs($this->admin)
        ->get('/server/console')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rcon.available', false)
            ->has('recentEntries', 1)
            ->where('recentEntries.0.line', '[00:00:01 INFO]: Server started')
        );
});
