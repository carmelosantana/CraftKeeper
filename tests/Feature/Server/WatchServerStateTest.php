<?php

use App\Console\Commands\WatchServerState;
use App\Console\MinecraftRconClient;
use App\Console\PersistentRconClient;
use App\Models\ServerSample;
use App\Server\RetryBackoff;
use App\Server\ServerStatusService;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Tests\fixtures\rcon\FakeRconTransport;

/*
|--------------------------------------------------------------------------
| The long-running health poll
|--------------------------------------------------------------------------
|
| server:watch replaces what used to be a scheduled command running every
| 15 seconds. That mattered because Laravel runs a scheduled COMMAND event
| as a fresh `php artisan` OS process per tick (Illuminate\Console\
| Scheduling\CommandBuilder -> Process::fromShellCommandline), so nothing
| in-process could survive between ticks and every single poll was forced
| to open a new RCON connection — two lines in the operator's latest.log,
| ~11,500 a day, 96% of the whole file on a live Legendary container.
|
| One long-lived process CAN hold one connection, which is the entire
| point of this command. The connection accounting below is therefore the
| behavior under test, not an implementation detail.
|
*/

function bindPersistentRcon(FakeRconTransport $transport): MinecraftRconClient
{
    $client = new MinecraftRconClient($transport, persistent: true);
    app()->instance(PersistentRconClient::class, $client);

    return $client;
}

it('polls many times over a single RCON connection', function () {
    $transport = FakeRconTransport::respondingWith(
        rconAuthOkBytes().rconEmptyListReplyBytes().rconEmptyListReplyBytes().rconEmptyListReplyBytes(),
    );
    bindPersistentRcon($transport);
    Sleep::fake();

    Artisan::call('server:watch', ['--max-cycles' => 3]);

    expect(ServerSample::query()->count())->toBe(3)
        ->and($transport->connectCalls)->toBe(1);
});

it('records the parsed player count on every cycle, not just the first', function () {
    $transport = FakeRconTransport::respondingWith(
        rconAuthOkBytes()
            .rconCommandReplyBytes('There are 1 of a max of 20 players online: Alice')
            .rconCommandReplyBytes('There are 2 of a max of 20 players online: Alice, Bob'),
    );
    bindPersistentRcon($transport);
    Sleep::fake();

    Artisan::call('server:watch', ['--max-cycles' => 2]);

    $samples = ServerSample::query()->orderBy('id')->get();
    expect($samples->pluck('player_count')->all())->toBe([1, 2])
        ->and($samples->last()->player_names)->toBe(['Alice', 'Bob']);
});

it('releases the connection when it stops, so a restart never leaves a socket dangling', function () {
    $transport = FakeRconTransport::respondingWith(rconAuthOkBytes().rconEmptyListReplyBytes());
    bindPersistentRcon($transport);
    Sleep::fake();

    Artisan::call('server:watch', ['--max-cycles' => 1]);

    expect($transport->closeCalls)->toBe(1);
});

it('waits the poll interval between cycles', function () {
    $transport = FakeRconTransport::respondingWith(
        rconAuthOkBytes().rconEmptyListReplyBytes().rconEmptyListReplyBytes(),
    );
    bindPersistentRcon($transport);
    Sleep::fake();

    Artisan::call('server:watch', ['--max-cycles' => 2]);

    Sleep::assertSleptTimes(2);
    // Asserted against the LITERAL 15, not the constant — comparing the
    // constant to itself would pass no matter what the cadence became.
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 15.0, times: 2);
});

it('polls about three times per staleness window, so a missed tick does not flip the UI to unavailable', function () {
    // ServerStatusService treats a sample older than SAMPLE_FRESHNESS_SECONDS
    // as untrustworthy. The two numbers are a matched pair: the poll must
    // be frequent enough that a couple of missed ticks are survivable, and
    // slow enough not to be chatty. Pinning the RELATIONSHIP means moving
    // one without the other fails here rather than silently making the
    // dashboard flicker (or the poll noisy) in production.
    expect(ServerStatusService::SAMPLE_FRESHNESS_SECONDS / WatchServerState::POLL_INTERVAL_SECONDS)->toBe(3);
});

it('keeps polling after a failed cycle instead of exiting', function () {
    // The connection dies after the first reply; the sampler must record
    // the failure and the loop must survive it. A daemon that exits on
    // the first blip would leave ServerStatusService with nothing but
    // stale samples until supervisor noticed.
    $transport = FakeRconTransport::respondingWith(rconAuthOkBytes().rconEmptyListReplyBytes());
    bindPersistentRcon($transport);
    Sleep::fake();

    $exitCode = Artisan::call('server:watch', ['--max-cycles' => 2]);

    expect($exitCode)->toBe(0)
        ->and(ServerSample::query()->count())->toBe(2)
        ->and(ServerSample::query()->orderBy('id')->get()->pluck('rcon_reachable')->all())
        ->toBe([true, false]);
});

it('honours the backoff window instead of hammering an unreachable server', function () {
    // Same deterministic pin SampleServerStateTest uses: RetryBackoff's
    // random source at 1.0 makes nextDelaySeconds(1) exactly 15.0s.
    app()->instance(RetryBackoff::class, new RetryBackoff(fn () => 1.0));

    bindPersistentRcon(FakeRconTransport::connectTimesOut());
    Sleep::fake();

    Artisan::call('server:watch', ['--max-cycles' => 3]);

    // Cycle 1 attempts and fails; cycles 2 and 3 fall inside the 15s
    // window (faked sleep does not advance the clock), so they are cheap
    // no-ops that record nothing.
    expect(ServerSample::query()->count())->toBe(1);
});

it('reports a non-zero exit code if it cannot run at all', function () {
    // --max-cycles must be a positive integer; a typo should fail loudly
    // rather than silently spinning forever under supervisor.
    $exitCode = Artisan::call('server:watch', ['--max-cycles' => -1]);

    expect($exitCode)->not->toBe(0);
});
