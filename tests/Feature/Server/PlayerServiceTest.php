<?php

use App\Models\Player;
use App\Models\PlayerEvent;
use App\Server\LogEvent;
use App\Server\LogEventKind;
use App\Server\PlayerPlatform;
use App\Server\PlayerService;

function playerService(): PlayerService
{
    return app(PlayerService::class);
}

it('creates a new Player and a PlayerEvent for a Floodgate Bedrock join', function () {
    $now = now();

    playerService()->record([
        new LogEvent(LogEventKind::Join, '.aacarm', PlayerPlatform::Bedrock, null, '[floodgate] Floodgate player logged in as .aacarm'),
    ], $now);

    $player = Player::query()->where('username', '.aacarm')->sole();
    expect($player->platform)->toBe(PlayerPlatform::Bedrock)
        ->and($player->first_seen_at->toDateTimeString())->toBe($now->toDateTimeString())
        ->and($player->last_seen_at->toDateTimeString())->toBe($now->toDateTimeString());

    $event = PlayerEvent::query()->where('player_id', $player->id)->sole();
    expect($event->kind)->toBe(LogEventKind::Join)
        ->and($event->platform)->toBe(PlayerPlatform::Bedrock)
        ->and($event->raw_line)->toBe('[floodgate] Floodgate player logged in as .aacarm');
});

it('defaults platform to Java when a standard join/leave line carries no platform signal', function () {
    playerService()->record([
        new LogEvent(LogEventKind::Join, 'Steve', PlayerPlatform::Java, null, 'Steve joined the game'),
    ], now());

    expect(Player::query()->where('username', 'Steve')->sole()->platform)->toBe(PlayerPlatform::Java);
});

it('upserts the same player across multiple events instead of duplicating', function () {
    $first = now();
    $second = $first->clone()->addMinutes(5);

    playerService()->record([new LogEvent(LogEventKind::Join, 'Steve', PlayerPlatform::Java, null, 'Steve joined the game')], $first);
    playerService()->record([new LogEvent(LogEventKind::Leave, 'Steve', PlayerPlatform::Java, null, 'Steve left the game')], $second);

    expect(Player::query()->where('username', 'Steve')->count())->toBe(1);

    $player = Player::query()->where('username', 'Steve')->sole();
    expect($player->first_seen_at->toDateTimeString())->toBe($first->toDateTimeString())
        ->and($player->last_seen_at->toDateTimeString())->toBe($second->toDateTimeString());

    expect(PlayerEvent::query()->where('player_id', $player->id)->count())->toBe(2);
});

it('retroactively upgrades a player to Bedrock once a Floodgate signal is observed', function () {
    playerService()->record([new LogEvent(LogEventKind::Join, '.aacarm', PlayerPlatform::Java, null, 'raw')], now());
    expect(Player::query()->where('username', '.aacarm')->sole()->platform)->toBe(PlayerPlatform::Java);

    playerService()->record([new LogEvent(LogEventKind::Join, '.aacarm', PlayerPlatform::Bedrock, null, '[floodgate] ...')], now());
    expect(Player::query()->where('username', '.aacarm')->sole()->platform)->toBe(PlayerPlatform::Bedrock);
});

it('records a kick event with its reason and a chat event with its message', function () {
    playerService()->record([
        new LogEvent(LogEventKind::Kick, '.aacarm', null, 'floating too long!', '.aacarm was kicked for floating too long!'),
    ], now());

    $kick = PlayerEvent::query()->where('kind', LogEventKind::Kick)->sole();
    expect($kick->message)->toBe('floating too long!');

    playerService()->record([
        new LogEvent(LogEventKind::Chat, 'Steve', null, 'hello world', '<Steve> hello world'),
    ], now());

    $chat = PlayerEvent::query()->where('kind', LogEventKind::Chat)->sole();
    expect($chat->message)->toBe('hello world');
});

it('never creates a Player or PlayerEvent for an Unknown-kind event', function () {
    playerService()->record([
        new LogEvent(LogEventKind::Unknown, null, null, null, 'Starting minecraft server'),
    ], now());

    expect(Player::query()->count())->toBe(0)
        ->and(PlayerEvent::query()->count())->toBe(0);
});

it('exposes recent events newest first', function () {
    $first = now()->subMinutes(10);
    $second = now();

    playerService()->record([new LogEvent(LogEventKind::Join, 'Steve', PlayerPlatform::Java, null, 'raw1')], $first);
    playerService()->record([new LogEvent(LogEventKind::Leave, 'Steve', PlayerPlatform::Java, null, 'raw2')], $second);

    $events = playerService()->recentEvents();

    expect($events->first()->kind)->toBe(LogEventKind::Leave)
        ->and($events->last()->kind)->toBe(LogEventKind::Join);
});
