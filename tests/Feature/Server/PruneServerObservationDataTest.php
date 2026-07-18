<?php

use App\Console\Commands\PruneServerObservationData;
use App\Models\ConsoleEntry;
use App\Models\Player;
use App\Models\PlayerEvent;
use App\Models\ServerSample;
use Illuminate\Support\Facades\Artisan;

it('prunes ServerSample rows older than 7 days but keeps newer ones', function () {
    $old = ServerSample::query()->create([
        'sampled_at' => now()->subDays(8),
        'rcon_reachable' => true,
        'player_count' => 1,
        'player_names' => ['Steve'],
    ]);
    $recent = ServerSample::query()->create([
        'sampled_at' => now()->subDays(1),
        'rcon_reachable' => true,
        'player_count' => 1,
        'player_names' => ['Steve'],
    ]);
    $boundary = ServerSample::query()->create([
        'sampled_at' => now()->subDays(7)->subMinute(),
        'rcon_reachable' => true,
        'player_count' => 1,
        'player_names' => ['Steve'],
    ]);

    Artisan::call('server:prune-observation-data');

    expect(ServerSample::query()->find($old->id))->toBeNull()
        ->and(ServerSample::query()->find($boundary->id))->toBeNull()
        ->and(ServerSample::query()->find($recent->id))->not->toBeNull();
});

it('prunes PlayerEvent rows older than 30 days but keeps newer ones', function () {
    $player = Player::query()->create([
        'username' => 'Steve',
        'platform' => 'java',
        'first_seen_at' => now()->subDays(40),
        'last_seen_at' => now(),
    ]);

    $old = PlayerEvent::query()->create([
        'player_id' => $player->id,
        'kind' => 'join',
        'raw_line' => 'raw',
        'occurred_at' => now()->subDays(31),
    ]);
    $recent = PlayerEvent::query()->create([
        'player_id' => $player->id,
        'kind' => 'leave',
        'raw_line' => 'raw',
        'occurred_at' => now()->subDays(1),
    ]);

    Artisan::call('server:prune-observation-data');

    expect(PlayerEvent::query()->find($old->id))->toBeNull()
        ->and(PlayerEvent::query()->find($recent->id))->not->toBeNull();

    // The player identity itself is untouched by pruning — only the
    // bounded event history is trimmed.
    expect(Player::query()->find($player->id))->not->toBeNull();
});

it('prunes ConsoleEntry rows older than 24 hours but keeps newer ones', function () {
    $old = ConsoleEntry::query()->create(['line' => 'old', 'occurred_at' => now()->subHours(25)]);
    $recent = ConsoleEntry::query()->create(['line' => 'recent', 'occurred_at' => now()->subHours(1)]);

    Artisan::call('server:prune-observation-data');

    expect(ConsoleEntry::query()->find($old->id))->toBeNull()
        ->and(ConsoleEntry::query()->find($recent->id))->not->toBeNull();
});

it('reports how many rows of each kind were pruned', function () {
    ServerSample::query()->create(['sampled_at' => now()->subDays(8), 'rcon_reachable' => true]);

    Artisan::call(PruneServerObservationData::class);

    expect(Artisan::output())->toContain('Pruned 1 server sample');
});
