<?php

use App\Models\Operation;
use App\Models\Player;
use App\Models\PlayerEvent;
use App\Models\User;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use App\Server\LogEventKind;
use App\Server\PlayerPlatform;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
    $this->admin = User::factory()->create();
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
});

it('requires authentication', function () {
    $this->get('/overview')->assertRedirect('/login');
});

it('reports RCON and resources as honestly unavailable when nothing has ever been sampled or collected — never a fabricated zero', function () {
    $this->actingAs($this->admin)
        ->get('/overview')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Overview')
            ->where('health.rcon.available', false)
            ->where('health.rcon.playerCount', null)
            ->where('resources.available', false)
        );
});

it('shows recent operations and recent player activity', function () {
    Operation::factory()->ofType(OperationType::RconCommand)->status(OperationStatus::Succeeded)->create(['target' => 'list']);

    $player = Player::query()->create([
        'username' => 'Steve',
        'platform' => PlayerPlatform::Java,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    PlayerEvent::query()->create([
        'player_id' => $player->id,
        'kind' => LogEventKind::Join,
        'platform' => PlayerPlatform::Java,
        'raw_line' => 'Steve joined the game',
        'occurred_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get('/overview')
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentOperations', 1)
            ->has('recentPlayerActivity', 1)
            ->where('recentPlayerActivity.0.player', 'Steve')
        );
});

it('flags a pending restart when a succeeded restart-impacting config change has no later successful server stop', function () {
    Operation::factory()->ofType(OperationType::ConfigApply)->status(OperationStatus::Succeeded)->create([
        'redacted_input' => ['restart_impact' => 'restart'],
        'finished_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get('/overview')
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingRestart', true)
            ->where(
                'attentionItems',
                fn ($items) => collect($items)->contains(fn ($item) => $item['kind'] === 'pending-restart'),
            )
        );
});

it('clears the pending-restart flag once a server stop succeeds afterward', function () {
    Operation::factory()->ofType(OperationType::ConfigApply)->status(OperationStatus::Succeeded)->create([
        'redacted_input' => ['restart_impact' => 'restart'],
        'finished_at' => now()->subMinute(),
    ]);
    Operation::factory()->ofType(OperationType::ServerStop)->status(OperationStatus::Succeeded)->create([
        'finished_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get('/overview')
        ->assertInertia(fn (Assert $page) => $page->where('pendingRestart', false));
});

it('surfaces the most recent failed operation as an attention item', function () {
    Operation::factory()->ofType(OperationType::RconCommand)->status(OperationStatus::Failed)->create([
        'outcome' => 'The command could not be completed.',
    ]);

    $this->actingAs($this->admin)
        ->get('/overview')
        ->assertInertia(fn (Assert $page) => $page->where(
            'attentionItems',
            fn ($items) => collect($items)->contains(fn ($item) => $item['kind'] === 'operation-failed'),
        ));
});
