<?php

use App\Console\CommandConsequences;
use App\Console\CommandPolicy;

function commandConsequences(): CommandConsequences
{
    return new CommandConsequences(new CommandPolicy);
}

it('describes every elevated command the task brief names', function (string $command, string $expected) {
    expect(commandConsequences()->describe($command))->toBe($expected);
})->with([
    ['stop', 'Stops the Minecraft server.'],
    ['op Steve', 'Grants a player operator (admin) privileges.'],
    ['deop Steve', "Revokes a player's operator (admin) privileges."],
    ['ban Steve griefing', 'Bans a player from the server, disconnecting them immediately.'],
    ['whitelist add Steve', 'Changes who is allowed to join the server.'],
    ['gamerule keepInventory true', 'Changes a server-wide game rule affecting every player.'],
    ['execute as Steve run say hi', 'Runs another command as a different entity or context — can perform any other elevated action.'],
]);

it('describes every predefined safe action', function (string $command) {
    expect(commandConsequences()->describe($command))->not->toBe('This command is not on the predefined safe list and may change server or player state.');
})->with([
    'list',
    'save-all flush',
    'say hello everyone',
    'time query daytime',
    'weather query',
]);

it('falls back to a generic, honest consequence for an unrecognized elevated command', function () {
    expect(commandConsequences()->describe('totally-unknown-command with args'))
        ->toBe('This command is not on the predefined safe list and may change server or player state.');
});

it('keys the lookup on the command category, ignoring arguments', function () {
    expect(commandConsequences()->describe('op Alice'))->toBe(commandConsequences()->describe('op Bob'));
});
