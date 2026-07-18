<?php

use App\Console\CommandPolicy;
use App\Console\CommandRisk;
use App\Operations\InputRedactor;

/*
|--------------------------------------------------------------------------
| The brief's verbatim elevated-classification test
|--------------------------------------------------------------------------
*/

it('classifies stop, op, deop, ban, whitelist, gamerule, and raw execute as elevated', function (string $command) {
    expect(app(CommandPolicy::class)->classify($command))->toBe(CommandRisk::Elevated);
})->with([
    'stop',
    'op Steve',
    'deop Steve',
    'ban Steve',
    'whitelist on',
    'gamerule keepInventory true',
    'execute as @a run kill @s',
]);

/*
|--------------------------------------------------------------------------
| The safe, predefined allow-list — and nothing else
|--------------------------------------------------------------------------
*/

it('classifies the predefined safe actions as safe', function (string $command) {
    expect(app(CommandPolicy::class)->classify($command))->toBe(CommandRisk::Safe);
})->with([
    'list',
    'save-all flush',
    'say hello there',
    'say gg',
    'time query daytime',
    'weather query',
]);

it('normalizes leading/trailing whitespace around an otherwise safe command without downgrading it', function () {
    expect(app(CommandPolicy::class)->classify('   list   '))->toBe(CommandRisk::Safe)
        ->and(app(CommandPolicy::class)->classify("\tsave-all flush\n"))->toBe(CommandRisk::Safe);
});

it('collapses internal whitespace runs before matching', function () {
    expect(app(CommandPolicy::class)->classify("save-all\t\tflush"))->toBe(CommandRisk::Safe);
});

/*
|--------------------------------------------------------------------------
| Whitespace/injection tricks must never downgrade risk
|--------------------------------------------------------------------------
*/

it('never lets a trailing-content trick past the safe allow-list', function (string $command) {
    expect(app(CommandPolicy::class)->classify($command))->toBe(CommandRisk::Elevated);
})->with([
    'list; op me',
    'list op',
    'listsay hello',
    'LIST',
    'save-all',
    'save-all flush now',
    'say',
    'SAY hello',
    'time query',
    'weather',
]);

it('classifies a command containing an embedded NUL byte as elevated', function () {
    expect(app(CommandPolicy::class)->classify("list\0op me"))->toBe(CommandRisk::Elevated);
});

it('classifies an empty or whitespace-only command as elevated', function () {
    expect(app(CommandPolicy::class)->classify(''))->toBe(CommandRisk::Elevated)
        ->and(app(CommandPolicy::class)->classify('   '))->toBe(CommandRisk::Elevated);
});

/*
|--------------------------------------------------------------------------
| Category / secret-pattern detection / redacted display
|--------------------------------------------------------------------------
*/

it('derives a stable category from the first normalized token', function () {
    $policy = app(CommandPolicy::class);

    expect($policy->category('op Steve'))->toBe('op')
        ->and($policy->category('  Gamerule keepInventory true'))->toBe('gamerule')
        ->and($policy->category(''))->toBe('unknown');
});

it('flags a known secret-taking command name as secret-like', function () {
    $policy = app(CommandPolicy::class);

    expect($policy->looksLikeSecret('login mySuperSecretPass123'))->toBeTrue()
        ->and($policy->looksLikeSecret('register p@ssw0rd p@ssw0rd'))->toBeTrue()
        ->and($policy->looksLikeSecret('changepassword old new'))->toBeTrue();
});

it('flags a literal password/token/secret-looking substring as secret-like regardless of command name', function () {
    $policy = app(CommandPolicy::class);

    expect($policy->looksLikeSecret('someplugin password=hunter2'))->toBeTrue()
        ->and($policy->looksLikeSecret('someplugin token: abc123'))->toBeTrue()
        ->and($policy->looksLikeSecret('op Steve'))->toBeFalse()
        ->and($policy->looksLikeSecret('ban Steve'))->toBeFalse();
});

it('does not false-positive on a word that merely contains "secret" as a substring', function () {
    // "Secretive" is one contiguous word token — \bsecret\b must not
    // match inside it.
    expect(app(CommandPolicy::class)->looksLikeSecret('say the Secretive Society'))->toBeFalse();
});

it('produces a masked redacted display for a secret-like command, and a plain normalized one otherwise', function () {
    $policy = app(CommandPolicy::class);

    expect($policy->redactedDisplay('login mySuperSecretPass123'))
        ->toBe('login '.InputRedactor::MASK)
        ->and($policy->redactedDisplay('  op   Steve  '))
        ->toBe('op Steve');
});
