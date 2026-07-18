<?php

use App\Models\Player;
use App\Models\ServerSample;
use App\Models\User;
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

it('requires authentication for /server and /server/players', function () {
    $this->get('/server')->assertRedirect('/login');
    $this->get('/server/players')->assertRedirect('/login');
});

it('shows the version as unavailable, with a reason, when nothing was found — never a guessed version', function () {
    $this->actingAs($this->admin)
        ->get('/server')
        ->assertInertia(fn (Assert $page) => $page
            ->component('server/Index')
            ->where('version.known', false)
            ->where('version.label', null)
            ->whereNot('version.reason', null)
        );
});

it('lists every predefined safe action with a real consequence string', function () {
    $this->actingAs($this->admin)
        ->get('/server')
        ->assertInertia(fn (Assert $page) => $page
            ->has('predefinedActions', 5)
            ->where('predefinedActions.0.consequence', fn ($value) => is_string($value) && $value !== '')
        );
});

it('marks player online status as unknown (null), never false, when RCON is unavailable', function () {
    Player::query()->create([
        'username' => 'Steve',
        'platform' => PlayerPlatform::Java,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get('/server/players')
        ->assertInertia(fn (Assert $page) => $page
            ->component('server/Players')
            ->where('rconAvailable', false)
            ->where('players.0.username', 'Steve')
            ->where('players.0.platform', 'java')
            ->where('players.0.online', null)
        );
});

it('marks a player online/offline correctly using the exact observed username when RCON is available', function () {
    Player::query()->create(['username' => 'Alex', 'platform' => PlayerPlatform::Bedrock, 'first_seen_at' => now()->subMinute(), 'last_seen_at' => now()->subMinute()]);
    Player::query()->create(['username' => 'Steve', 'platform' => PlayerPlatform::Java, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => true,
        'player_count' => 1,
        'player_names' => ['Steve'],
        'error_reason' => null,
    ]);

    $this->actingAs($this->admin)
        ->get('/server/players')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rconAvailable', true)
            ->where('players.0.username', 'Steve')
            ->where('players.0.online', true)
            ->where('players.1.username', 'Alex')
            ->where('players.1.online', false)
        );
});
