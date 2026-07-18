<?php

use App\Ai\SecretRedactor;

/*
|--------------------------------------------------------------------------
| The task brief's own Step 1 test, verbatim
|--------------------------------------------------------------------------
*/

it('redacts configured and discovered secrets before hosted provider transport', function () {
    $result = app(SecretRedactor::class)->redact(
        "rcon.password=hunter2\napi-key: sk-example-secret\n",
        ['hunter2', 'sk-example-secret']
    );

    expect($result->text)->not->toContain('hunter2', 'sk-example-secret')
        ->and($result->disclosures)->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Additional coverage
|--------------------------------------------------------------------------
*/

it('masks every occurrence of a repeated secret value and reports the occurrence count once', function () {
    $result = app(SecretRedactor::class)->redact(
        "token=abc123\nbackup-token=abc123\n",
        ['abc123'],
    );

    expect($result->text)->not->toContain('abc123')
        ->and($result->disclosures)->toHaveCount(1)
        ->and($result->disclosures[0]->occurrences)->toBe(2);
});

it('discloses nothing when none of the known secret values appear in the text', function () {
    $result = app(SecretRedactor::class)->redact('motd=hello world', ['hunter2', 'sk-example-secret']);

    expect($result->text)->toBe('motd=hello world')
        ->and($result->disclosures)->toBeEmpty();
});

it('ignores empty and duplicate secret values without erroring', function () {
    $result = app(SecretRedactor::class)->redact('password=abc', ['', 'abc', 'abc']);

    expect($result->text)->not->toContain('abc')
        ->and($result->disclosures)->toHaveCount(1);
});

it('attaches a human label to a disclosure when one is supplied', function () {
    $result = app(SecretRedactor::class)->redact(
        'rcon.password=hunter2',
        ['hunter2'],
        ['hunter2' => 'rcon.password'],
    );

    expect($result->disclosures[0]->label)->toBe('rcon.password');
});

it('never leaves a partial secret behind: the mask never itself contains the raw value', function () {
    $result = app(SecretRedactor::class)->redact('key=hunter2hunter2', ['hunter2']);

    expect($result->text)->not->toContain('hunter2');
});
