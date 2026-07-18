<?php

use App\Catalog\CatalogSourceHealth;
use App\Models\CatalogSourceState;
use App\Plugins\PluginProvenance;

/**
 * Focused, no-HTTP coverage of App\Catalog\CatalogSourceHealth's
 * recordSuccess()/recordFailure() state transitions — called once per
 * live fetch attempt by App\Catalog\Sources\AbstractPluginSource::search()
 * (see that class's docblock), but previously exercised only
 * incidentally through source-level Http::fake() tests, never asserted
 * on directly. Thresholds/field names below are read straight off
 * App\Catalog\CatalogSourceHealth: `>= 3` consecutive failures flips
 * `status` to 'unavailable' (below that, 'degraded'); a success always
 * resets to 'ok' with `consecutive_failures` back to 0.
 */
beforeEach(function () {
    $this->health = app(CatalogSourceHealth::class);
});

it('creates a degraded row on the first recorded failure, with consecutive_failures at 1 and the error message stored', function () {
    $this->health->recordFailure(PluginProvenance::Hangar, 'Connection refused');

    $state = CatalogSourceState::query()->where('source', 'Hangar')->sole();

    expect($state->status)->toBe('degraded')
        ->and($state->consecutive_failures)->toBe(1)
        ->and($state->last_error)->toBe('Connection refused')
        ->and($state->last_attempt_at)->not->toBeNull()
        ->and($state->last_success_at)->toBeNull();
});

it('accumulates consecutive_failures across repeated failures, staying degraded below the 3-failure threshold', function () {
    $this->health->recordFailure(PluginProvenance::Hangar, 'first failure');
    $this->health->recordFailure(PluginProvenance::Hangar, 'second failure');

    $state = CatalogSourceState::query()->where('source', 'Hangar')->sole();

    expect($state->status)->toBe('degraded')
        ->and($state->consecutive_failures)->toBe(2)
        ->and($state->last_error)->toBe('second failure');
});

it('flips status to unavailable exactly when consecutive_failures reaches 3', function () {
    $this->health->recordFailure(PluginProvenance::Hangar, 'first');
    $this->health->recordFailure(PluginProvenance::Hangar, 'second');

    // Still degraded one failure short of the threshold.
    expect(CatalogSourceState::query()->where('source', 'Hangar')->sole()->status)->toBe('degraded');

    $this->health->recordFailure(PluginProvenance::Hangar, 'third');

    $state = CatalogSourceState::query()->where('source', 'Hangar')->sole();
    expect($state->status)->toBe('unavailable')
        ->and($state->consecutive_failures)->toBe(3);
});

it('remains unavailable and keeps incrementing consecutive_failures past the threshold', function () {
    foreach (['1st', '2nd', '3rd', '4th'] as $message) {
        $this->health->recordFailure(PluginProvenance::Hangar, $message);
    }

    $state = CatalogSourceState::query()->where('source', 'Hangar')->sole();

    expect($state->status)->toBe('unavailable')
        ->and($state->consecutive_failures)->toBe(4)
        ->and($state->last_error)->toBe('4th');
});

it('recordSuccess() resets consecutive_failures to 0, clears last_error, sets last_success_at, and returns status to ok — even from unavailable', function () {
    $this->health->recordFailure(PluginProvenance::Hangar, 'first');
    $this->health->recordFailure(PluginProvenance::Hangar, 'second');
    $this->health->recordFailure(PluginProvenance::Hangar, 'third');

    expect(CatalogSourceState::query()->where('source', 'Hangar')->sole()->status)->toBe('unavailable');

    $this->health->recordSuccess(PluginProvenance::Hangar);

    $state = CatalogSourceState::query()->where('source', 'Hangar')->sole();

    expect($state->status)->toBe('ok')
        ->and($state->consecutive_failures)->toBe(0)
        ->and($state->last_error)->toBeNull()
        ->and($state->last_success_at)->not->toBeNull()
        ->and($state->last_attempt_at)->not->toBeNull();
});

it('recordSuccess() on a source with no prior state creates a healthy row from scratch', function () {
    $this->health->recordSuccess(PluginProvenance::Modrinth);

    $state = CatalogSourceState::query()->where('source', 'Modrinth')->sole();

    expect($state->status)->toBe('ok')
        ->and($state->consecutive_failures)->toBe(0)
        ->and($state->last_success_at)->not->toBeNull()
        ->and($state->last_error)->toBeNull();
});

it('a later failure after a success starts consecutive_failures over at 1, not resuming the earlier streak', function () {
    $this->health->recordFailure(PluginProvenance::Catalog, 'first');
    $this->health->recordFailure(PluginProvenance::Catalog, 'second');
    $this->health->recordSuccess(PluginProvenance::Catalog);
    $this->health->recordFailure(PluginProvenance::Catalog, 'new failure');

    $state = CatalogSourceState::query()->where('source', 'Catalog')->sole();

    expect($state->status)->toBe('degraded')
        ->and($state->consecutive_failures)->toBe(1)
        ->and($state->last_error)->toBe('new failure');
});

it('tracks each source independently — a Hangar failure never touches Modrinth\'s row', function () {
    $this->health->recordFailure(PluginProvenance::Hangar, 'hangar down');
    $this->health->recordSuccess(PluginProvenance::Modrinth);

    expect(CatalogSourceState::query()->where('source', 'Hangar')->sole()->status)->toBe('degraded')
        ->and(CatalogSourceState::query()->where('source', 'Modrinth')->sole()->status)->toBe('ok');
});

it('snapshot() returns null when no state has ever been recorded for a source', function () {
    expect($this->health->snapshot(PluginProvenance::Catalog))->toBeNull();
});

it('snapshot() returns the persisted row reflecting the most recent recorded outcome', function () {
    $this->health->recordFailure(PluginProvenance::Catalog, 'boom');

    $snapshot = $this->health->snapshot(PluginProvenance::Catalog);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->status)->toBe('degraded')
        ->and($snapshot->consecutive_failures)->toBe(1);
});
