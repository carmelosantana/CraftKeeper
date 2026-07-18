<?php

use App\Server\LogEventKind;
use App\Server\LogParser;
use App\Server\PlayerPlatform;

/*
|--------------------------------------------------------------------------
| The brief's verbatim test
|--------------------------------------------------------------------------
*/

it('parses Floodgate Bedrock join and floating kick without dropping the raw line', function () {
    $events = app(LogParser::class)->parse([
        '[12:24:20 INFO]: [floodgate] Floodgate player logged in as .aacarm',
        '[12:24:27 WARN]: .aacarm was kicked for floating too long!',
    ]);

    expect($events[0]->player)->toBe('.aacarm')
        ->and($events[0]->platform)->toBe(PlayerPlatform::Bedrock)
        ->and($events[1]->kind)->toBe(LogEventKind::Kick);
});

it('retains the exact original raw line on every event, recognized or not', function () {
    $lines = [
        '[12:24:20 INFO]: [floodgate] Floodgate player logged in as .aacarm',
        '[12:24:27 WARN]: .aacarm was kicked for floating too long!',
    ];

    $events = app(LogParser::class)->parse($lines);

    expect($events[0]->raw)->toBe($lines[0])
        ->and($events[1]->raw)->toBe($lines[1]);
});

/*
|--------------------------------------------------------------------------
| Vanilla/Paper join, leave, chat
|--------------------------------------------------------------------------
*/

it('parses a standard vanilla join line as Java by default (no Floodgate signal)', function () {
    $events = app(LogParser::class)->parse(['[10:00:00 INFO]: Steve joined the game']);

    expect($events[0]->kind)->toBe(LogEventKind::Join)
        ->and($events[0]->player)->toBe('Steve')
        ->and($events[0]->platform)->toBe(PlayerPlatform::Java);
});

it('parses a standard vanilla leave line', function () {
    $events = app(LogParser::class)->parse(['[10:05:00 INFO]: Steve left the game']);

    expect($events[0]->kind)->toBe(LogEventKind::Leave)
        ->and($events[0]->player)->toBe('Steve')
        ->and($events[0]->platform)->toBe(PlayerPlatform::Java);
});

it('parses a chat line, extracting the player and message', function () {
    $events = app(LogParser::class)->parse(['[10:06:00 INFO]: <Steve> hello world']);

    expect($events[0]->kind)->toBe(LogEventKind::Chat)
        ->and($events[0]->player)->toBe('Steve')
        ->and($events[0]->message)->toBe('hello world');
});

it('parses a kick with no explicit reason', function () {
    $events = app(LogParser::class)->parse(['[10:07:00 INFO]: Steve was kicked']);

    expect($events[0]->kind)->toBe(LogEventKind::Kick)
        ->and($events[0]->player)->toBe('Steve')
        ->and($events[0]->message)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Chat is classified before kick — a <...>-prefixed chat body that
| happens to start with "was kicked" is still chat, never misread as a
| Kick event with a bracketed "player" name.
|--------------------------------------------------------------------------
*/

it('classifies a chat line whose body starts with "was kicked" as Chat, not Kick', function () {
    $events = app(LogParser::class)->parse(['[12:00:00 INFO]: <Steve> was kicked for being awesome']);

    expect($events[0]->kind)->toBe(LogEventKind::Chat)
        ->and($events[0]->player)->toBe('Steve')
        ->and($events[0]->message)->toBe('was kicked for being awesome');
});

it('still classifies a real, unprefixed kick line as Kick', function () {
    $events = app(LogParser::class)->parse(['[12:24:27 WARN]: .aacarm was kicked for floating too long!']);

    expect($events[0]->kind)->toBe(LogEventKind::Kick)
        ->and($events[0]->player)->toBe('.aacarm')
        ->and($events[0]->message)->toBe('floating too long!');
});

/*
|--------------------------------------------------------------------------
| The classic thread-qualified logs/latest.log envelope
|--------------------------------------------------------------------------
*/

it('also recognizes the classic "[time] [thread/level]:" file-log envelope', function () {
    $events = app(LogParser::class)->parse(['[00:00:05] [Server thread/INFO]: Steve joined the game']);

    expect($events[0]->kind)->toBe(LogEventKind::Join)
        ->and($events[0]->player)->toBe('Steve');
});

/*
|--------------------------------------------------------------------------
| Never drop a line — unrecognized lines are retained as Unknown
|--------------------------------------------------------------------------
*/

it('never drops a line: an unrecognized line is retained with kind Unknown and its raw text intact', function () {
    $lines = [
        '[00:00:00] [Server thread/INFO]: Starting minecraft server',
        'garbage with no envelope at all',
        '',
    ];

    $events = app(LogParser::class)->parse($lines);

    expect($events)->toHaveCount(3);

    foreach ($events as $index => $event) {
        expect($event->kind)->toBe(LogEventKind::Unknown)
            ->and($event->player)->toBeNull()
            ->and($event->platform)->toBeNull()
            ->and($event->raw)->toBe($lines[$index]);
    }
});

it('preserves the exact input line count and order across a mixed batch, never dropping any line', function () {
    $lines = [
        '[12:24:20 INFO]: [floodgate] Floodgate player logged in as .aacarm',
        '[00:00:00] [Server thread/INFO]: Starting minecraft server',
        '[12:24:27 WARN]: .aacarm was kicked for floating too long!',
        'not a recognized line shape',
        '[10:06:00 INFO]: <Steve> hello world',
    ];

    $events = app(LogParser::class)->parse($lines);

    expect($events)->toHaveCount(count($lines));

    foreach ($events as $index => $event) {
        expect($event->raw)->toBe($lines[$index]);
    }

    expect(array_map(fn ($e) => $e->kind, $events))->toBe([
        LogEventKind::Join,
        LogEventKind::Unknown,
        LogEventKind::Kick,
        LogEventKind::Unknown,
        LogEventKind::Chat,
    ]);
});

it('returns an empty list for an empty input list', function () {
    expect(app(LogParser::class)->parse([]))->toBe([]);
});
