<?php

use App\Console\RconCommandService;
use App\Models\Operation;
use App\Models\Player;
use App\Models\PlayerEvent;
use App\Models\User;
use App\Operations\OperationActorType;
use App\Operations\OperationAuthor;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use App\Server\LogEventKind;
use App\Server\PlayerPlatform;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

it('requires authentication', function () {
    $this->get('/activity')->assertRedirect('/login');
});

it('lists the full, stable set of filterable sources, including the ones with no data yet', function () {
    $this->actingAs($this->admin)
        ->get('/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activity')
            ->where('sources', [
                'config', 'plugin', 'command', 'server-restart', 'player',
                'ai-proposal', 'api-call', 'mcp-call',
            ])
            ->has('items', 0)
        );
});

it('shows operations and player events together, newest first', function () {
    $older = Operation::factory()->ofType(OperationType::ConfigApply)->status(OperationStatus::Succeeded)->create([
        'target' => 'server.properties',
        'created_at' => now()->subMinutes(5),
    ]);

    $player = Player::query()->create(['username' => 'Steve', 'platform' => PlayerPlatform::Java, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    PlayerEvent::query()->create([
        'player_id' => $player->id,
        'kind' => LogEventKind::Join,
        'platform' => PlayerPlatform::Java,
        'raw_line' => 'Steve joined the game',
        'occurred_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get('/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 2)
            ->where('items.0.source', 'player')
            ->where('items.0.summary', 'Steve joined the game')
            ->where('items.1.id', 'operation:'.$older->id)
            ->where('items.1.source', 'config')
        );
});

it('never renders a secret-shaped command as raw text in an activity summary', function () {
    app(RconCommandService::class)->proposeCommand('login mySuperSecretPass123', OperationAuthor::user($this->admin->id));

    $response = $this->actingAs($this->admin)->get('/activity');

    $response->assertOk()->assertDontSee('mySuperSecretPass123', false);
});

it('filters by source', function () {
    Operation::factory()->ofType(OperationType::RconCommand)->status(OperationStatus::Succeeded)->create(['target' => 'list']);
    Operation::factory()->ofType(OperationType::ConfigApply)->status(OperationStatus::Succeeded)->create(['target' => 'server.properties']);

    $this->actingAs($this->admin)
        ->get('/activity?source=command')
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.source', 'command')
        );
});

it('filters by status', function () {
    Operation::factory()->ofType(OperationType::RconCommand)->status(OperationStatus::Succeeded)->create();
    Operation::factory()->ofType(OperationType::RconCommand)->status(OperationStatus::Failed)->create();

    $this->actingAs($this->admin)
        ->get('/activity?status=failed')
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.status', 'failed')
        );
});

it('every operation-sourced item carries the operation correlation id', function () {
    $operation = Operation::factory()->ofType(OperationType::RconCommand)->status(OperationStatus::Succeeded)->create();

    $this->actingAs($this->admin)
        ->get('/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.correlationId', $operation->correlation_id)
        );
});

/*
|--------------------------------------------------------------------------
| Fix 3: the three actor-provenance filters are wired to real predicates
| on author_origin, not permanently-dead filters over an empty feed.
|--------------------------------------------------------------------------
*/

it('an AI/API/MCP-authored operation appears under its corresponding filter and not under the others', function () {
    $ai = Operation::factory()->ofType(OperationType::ConfigApply)->authoredBy(OperationActorType::Ai, 'session-1', 'ai')->create();
    $api = Operation::factory()->ofType(OperationType::PluginRemove)->authoredBy(OperationActorType::Human, '1', 'api')->create();
    $mcp = Operation::factory()->ofType(OperationType::RconCommand)->authoredBy(OperationActorType::Mcp, 'client-1', 'mcp')->create();

    // Each filter returns EXACTLY the operation authored with that origin —
    // proving both "appears under its own filter" and, since each filter
    // returns only 1 item, "does not appear under the others" in one
    // assertion per filter.
    $this->actingAs($this->admin)
        ->get('/activity?source=ai-proposal')
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.id', 'operation:'.$ai->id)
            ->where('items.0.source', 'ai-proposal')
            ->where('items.0.actor.origin', 'ai')
        );

    $this->actingAs($this->admin)
        ->get('/activity?source=api-call')
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.id', 'operation:'.$api->id)
            ->where('items.0.source', 'api-call')
            ->where('items.0.actor.origin', 'api')
            ->where('items.0.actor.type', 'human')
        );

    $this->actingAs($this->admin)
        ->get('/activity?source=mcp-call')
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.id', 'operation:'.$mcp->id)
            ->where('items.0.source', 'mcp-call')
            ->where('items.0.actor.origin', 'mcp')
        );
});
