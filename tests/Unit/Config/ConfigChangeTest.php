<?php

use App\Config\ConfigChange;
use App\Config\ConfigChangeKind;

it('builds a replace change carrying its path and value', function () {
    $change = ConfigChange::replace('allow-flight', true);

    expect($change->kind)->toBe(ConfigChangeKind::Replace)
        ->and($change->path)->toBe('allow-flight')
        ->and($change->value)->toBeTrue();
});

it('builds an add change carrying its path and value', function () {
    $change = ConfigChange::add('bedrock.port', 19132);

    expect($change->kind)->toBe(ConfigChangeKind::Add)
        ->and($change->path)->toBe('bedrock.port')
        ->and($change->value)->toBe(19132);
});

it('builds a remove change with a null value', function () {
    $change = ConfigChange::remove('motd');

    expect($change->kind)->toBe(ConfigChangeKind::Remove)
        ->and($change->path)->toBe('motd')
        ->and($change->value)->toBeNull();
});
