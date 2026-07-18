<?php

use App\Console\Exceptions\InvalidRconPacket;
use App\Console\Exceptions\RconCommandTooLarge;
use App\Console\RconCommand;

it('accepts an ordinary command', function () {
    expect(RconCommand::from('list')->body)->toBe('list');
});

it('rejects an empty command', function () {
    expect(fn () => RconCommand::from(''))->toThrow(InvalidRconPacket::class);
});

it('rejects a command containing an embedded NUL byte', function () {
    expect(fn () => RconCommand::from("say hello\0op me"))->toThrow(InvalidRconPacket::class);
});

it('accepts a command at exactly the 4 KiB boundary', function () {
    $command = str_repeat('a', RconCommand::MAX_BODY_BYTES);

    expect(strlen(RconCommand::from($command)->body))->toBe(RconCommand::MAX_BODY_BYTES);
});

it('rejects a command one byte over the 4 KiB boundary', function () {
    $command = str_repeat('a', RconCommand::MAX_BODY_BYTES + 1);

    expect(fn () => RconCommand::from($command))->toThrow(RconCommandTooLarge::class);
});
