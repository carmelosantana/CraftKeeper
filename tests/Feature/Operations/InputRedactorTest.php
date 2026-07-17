<?php

use App\Operations\InputRedactor;

it('masks values stored under sensitive key names', function () {
    $redacted = InputRedactor::redact([
        'password' => 'hunter2',
        'rcon_password' => 'hunter2',
        'api_key' => 'sk-live-abc',
        'apiKey' => 'sk-live-abc',
        'secret' => 'shh',
        'auth_token' => 'tok_abc',
        'private_key' => '-----BEGIN KEY-----',
    ]);

    foreach ($redacted as $value) {
        expect($value)->toBe(InputRedactor::MASK);
    }
});

it('leaves ordinary values untouched', function () {
    $redacted = InputRedactor::redact([
        'allow-flight' => 'true',
        'difficulty' => 'normal',
        'max-players' => 20,
    ]);

    expect($redacted)->toBe([
        'allow-flight' => 'true',
        'difficulty' => 'normal',
        'max-players' => 20,
    ]);
});

it('redacts sensitive keys inside nested arrays', function () {
    $redacted = InputRedactor::redact([
        'changes' => [
            'allow-flight' => 'true',
            'rcon' => [
                'password' => 'hunter2',
            ],
        ],
    ]);

    expect($redacted['changes']['allow-flight'])->toBe('true')
        ->and($redacted['changes']['rcon']['password'])->toBe(InputRedactor::MASK);
});
