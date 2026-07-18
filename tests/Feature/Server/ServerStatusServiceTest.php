<?php

use App\Models\ServerSample;
use App\Server\ServerStatusService;
use Illuminate\Support\Facades\File;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
});

function statusService(): ServerStatusService
{
    return app(ServerStatusService::class);
}

function withLogFile(string $root): void
{
    File::makeDirectory($root.'/logs', 0755, true, true);
    file_put_contents($root.'/logs/latest.log', "[00:00:00 INFO]: Starting minecraft server\n");
}

/*
|--------------------------------------------------------------------------
| Degraded-state isolation: RCON down marks ONLY the RCON card
|--------------------------------------------------------------------------
*/

it('marks only the RCON card unavailable when there is no sample yet, while file-based logs stay usable', function () {
    withLogFile($this->minecraftRoot);

    $snapshot = statusService()->snapshot();

    expect($snapshot->rcon->available)->toBeFalse()
        ->and($snapshot->rcon->reason)->not->toBeNull()
        ->and($snapshot->logs->available)->toBeTrue()
        ->and($snapshot->logs->reason)->toBeNull();
});

it('marks only the RCON card unavailable when the most recent sample reports RCON unreachable', function () {
    withLogFile($this->minecraftRoot);

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => false,
        'player_count' => null,
        'player_names' => null,
        'error_reason' => 'RCON connection timed out.',
    ]);

    $snapshot = statusService()->snapshot();

    expect($snapshot->rcon->available)->toBeFalse()
        ->and($snapshot->rcon->reason)->toBe('RCON connection timed out.')
        ->and($snapshot->logs->available)->toBeTrue();
});

it('marks logs unavailable independently of RCON, when RCON is reachable but the log file is missing', function () {
    // No log file created — Minecraft root exists but has no logs/latest.log.

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => true,
        'player_count' => 2,
        'player_names' => ['Alice', 'Bob'],
        'error_reason' => null,
    ]);

    $snapshot = statusService()->snapshot();

    expect($snapshot->rcon->available)->toBeTrue()
        ->and($snapshot->rcon->playerCount)->toBe(2)
        ->and($snapshot->logs->available)->toBeFalse()
        ->and($snapshot->logs->reason)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

it('reports RCON available with the real player count and names from a fresh sample', function () {
    withLogFile($this->minecraftRoot);

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => true,
        'player_count' => 2,
        'player_names' => ['Alice', 'Bob'],
        'error_reason' => null,
    ]);

    $snapshot = statusService()->snapshot();

    expect($snapshot->rcon->available)->toBeTrue()
        ->and($snapshot->rcon->playerCount)->toBe(2)
        ->and($snapshot->rcon->playerNames)->toBe(['Alice', 'Bob']);
});

/*
|--------------------------------------------------------------------------
| Staleness
|--------------------------------------------------------------------------
*/

it('treats a stale sample as unavailable even though it was itself a successful sample', function () {
    withLogFile($this->minecraftRoot);

    ServerSample::query()->create([
        'sampled_at' => now()->subMinutes(5),
        'rcon_reachable' => true,
        'player_count' => 1,
        'player_names' => ['Steve'],
        'error_reason' => null,
    ]);

    $snapshot = statusService()->snapshot();

    expect($snapshot->rcon->available)->toBeFalse()
        ->and($snapshot->rcon->reason)->not->toBeNull()
        ->and($snapshot->rcon->playerCount)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| No fabricated zero — the core invariant
|--------------------------------------------------------------------------
*/

it('never reports a fabricated zero player count when RCON is unavailable', function () {
    withLogFile($this->minecraftRoot);

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => false,
        'player_count' => null,
        'player_names' => null,
        'error_reason' => 'RCON auth failed.',
    ]);

    $snapshot = statusService()->snapshot();

    expect($snapshot->rcon->playerCount)->toBeNull()
        ->and($snapshot->rcon->playerCount)->not->toBe(0);
});

it('distinguishes a genuinely known zero (RCON reachable, 0 players online) from unavailable', function () {
    withLogFile($this->minecraftRoot);

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => true,
        'player_count' => 0,
        'player_names' => [],
        'error_reason' => null,
    ]);

    $snapshot = statusService()->snapshot();

    expect($snapshot->rcon->available)->toBeTrue()
        ->and($snapshot->rcon->playerCount)->toBe(0)
        ->and($snapshot->rcon->playerNames)->toBe([]);
});

it('reports unavailable, not a fabricated "available", when RCON is reachable but the sample carries a null player count (unrecognized "list" response)', function () {
    withLogFile($this->minecraftRoot);

    ServerSample::query()->create([
        'sampled_at' => now(),
        'rcon_reachable' => true,
        'player_count' => null,
        'player_names' => null,
        'error_reason' => 'The server returned an unrecognized response to "list".',
    ]);

    $snapshot = statusService()->snapshot();

    expect($snapshot->rcon->available)->toBeFalse()
        ->and($snapshot->rcon->reason)->not->toBeNull()
        ->and($snapshot->rcon->playerCount)->toBeNull()
        ->and($snapshot->rcon->playerNames)->toBeNull();
});
