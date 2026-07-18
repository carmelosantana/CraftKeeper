<?php

use App\Console\MinecraftRconClient;
use App\Console\RconClient;
use App\Models\ServerSample;
use App\Server\RetryBackoff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\fixtures\rcon\FakeRconTransport;

function bindFakeRcon(FakeRconTransport $transport): void
{
    app()->instance(RconClient::class, new MinecraftRconClient($transport));
}

function successfulListResponseBytes(string $body): string
{
    return FakeRconTransport::packet(1, 0, '')
        .FakeRconTransport::packet(2, 0, $body)
        .FakeRconTransport::packet(3, 0, '');
}

/*
|--------------------------------------------------------------------------
| Successful sampling
|--------------------------------------------------------------------------
*/

it('stores a ServerSample with the parsed player count and names on a successful list', function () {
    bindFakeRcon(FakeRconTransport::respondingWith(successfulListResponseBytes('There are 2 of a max of 20 players online: Alice, Bob')));

    Artisan::call('server:sample-state');

    $sample = ServerSample::query()->sole();
    expect($sample->rcon_reachable)->toBeTrue()
        ->and($sample->player_count)->toBe(2)
        ->and($sample->player_names)->toBe(['Alice', 'Bob'])
        ->and($sample->error_reason)->toBeNull();
});

it('stores a genuine zero player count when the server reports none online, never leaving it null', function () {
    bindFakeRcon(FakeRconTransport::respondingWith(successfulListResponseBytes('There are 0 of a max of 20 players online:')));

    Artisan::call('server:sample-state');

    $sample = ServerSample::query()->sole();
    expect($sample->rcon_reachable)->toBeTrue()
        ->and($sample->player_count)->toBe(0)
        ->and($sample->player_names)->toBe([]);
});

it('stores a null player count (not a fabricated zero) when the list response is unrecognized', function () {
    bindFakeRcon(FakeRconTransport::respondingWith(successfulListResponseBytes('Something unexpected entirely')));

    Artisan::call('server:sample-state');

    $sample = ServerSample::query()->sole();
    expect($sample->rcon_reachable)->toBeTrue()
        ->and($sample->player_count)->toBeNull()
        ->and($sample->error_reason)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| RCON unreachable
|--------------------------------------------------------------------------
*/

it('stores an unreachable ServerSample with a null player count and a reason when RCON auth fails', function () {
    bindFakeRcon(FakeRconTransport::respondingWith(FakeRconTransport::packet(-1, 0, '')));

    Artisan::call('server:sample-state');

    $sample = ServerSample::query()->sole();
    expect($sample->rcon_reachable)->toBeFalse()
        ->and($sample->player_count)->toBeNull()
        ->and($sample->player_names)->toBeNull()
        ->and($sample->error_reason)->not->toBeNull();
});

it('stores an unreachable ServerSample when the connection times out', function () {
    bindFakeRcon(FakeRconTransport::connectTimesOut());

    Artisan::call('server:sample-state');

    $sample = ServerSample::query()->sole();
    expect($sample->rcon_reachable)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Backoff: jitter + 60s ceiling, skip while backing off, reset on success
|--------------------------------------------------------------------------
*/

it('does not attempt RCON again (no new sample) while still within the backoff window after a failure', function () {
    bindFakeRcon(FakeRconTransport::respondingWith(FakeRconTransport::packet(-1, 0, '')));

    Artisan::call('server:sample-state');
    expect(ServerSample::query()->count())->toBe(1);

    // Immediately try again — the fake real random source means SOME
    // nonzero delay is virtually certain, so this tick should be a no-op.
    Artisan::call('server:sample-state');
    expect(ServerSample::query()->count())->toBe(1);
});

it('attempts RCON again once the backoff window has elapsed', function () {
    bindFakeRcon(FakeRconTransport::respondingWith(FakeRconTransport::packet(-1, 0, '')));

    $start = now();
    CarbonImmutable::setTestNow($start);

    Artisan::call('server:sample-state');
    expect(ServerSample::query()->count())->toBe(1);

    // Jump well past any possible backoff delay (max ceiling is 60s).
    CarbonImmutable::setTestNow($start->addSeconds(61));

    Artisan::call('server:sample-state');
    expect(ServerSample::query()->count())->toBe(2);

    CarbonImmutable::setTestNow();
});

it('resets backoff after a successful sample, so the very next tick attempts RCON again', function () {
    bindFakeRcon(FakeRconTransport::respondingWith(FakeRconTransport::packet(-1, 0, '')));
    Artisan::call('server:sample-state');
    expect(ServerSample::query()->count())->toBe(1);

    bindFakeRcon(FakeRconTransport::respondingWith(successfulListResponseBytes('There are 0 of a max of 20 players online:')));

    CarbonImmutable::setTestNow(now()->addSeconds(61));
    Artisan::call('server:sample-state');
    expect(ServerSample::query()->count())->toBe(2)
        ->and(ServerSample::query()->latest('id')->first()->rcon_reachable)->toBeTrue();

    // Backoff state was cleared by the success, so the IMMEDIATE next
    // tick (no time jump needed) attempts RCON again rather than
    // skipping.
    Artisan::call('server:sample-state');
    expect(ServerSample::query()->count())->toBe(3);

    CarbonImmutable::setTestNow();
});

it('never computes a backoff delay beyond the 60-second ceiling regardless of consecutive failures', function () {
    $backoff = new RetryBackoff(fn () => 1.0);

    for ($failures = 1; $failures <= 50; $failures++) {
        expect($backoff->nextDelaySeconds($failures))->toBeLessThanOrEqual(RetryBackoff::CEILING_SECONDS);
    }
});
