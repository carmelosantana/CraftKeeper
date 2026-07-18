<?php

use App\Console\Commands\PruneServerObservationData;
use App\Console\Commands\SampleServerState;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Server\LogTailService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

// CraftKeeper defines its own /up route (App\Http\Controllers\HealthController,
// registered in routes/web.php) with a richer readiness contract than the
// framework default, so the built-in `health:` route is intentionally not
// registered here. The maintenance-mode bypass the default would have
// granted is preserved explicitly so orchestrator health checks keep
// passing while the application is in maintenance mode.
PreventRequestsDuringMaintenance::except('/up');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Task 11's ambiguity resolution #1: poll lightweight RCON state
        // every 15 seconds while reachable. SampleServerState itself
        // applies a jittered backoff (up to a 60s ceiling) once RCON goes
        // unreachable, so this cadence is a CEILING on attempt frequency,
        // not a guarantee every tick calls out over the network.
        $schedule->command(SampleServerState::class)
            ->everyFifteenSeconds()
            ->withoutOverlapping();

        // Realtime console tailing (ambiguity resolutions #3/#6): a
        // bounded, purely local file read (<=256 KiB/iteration, no
        // network), so it runs far more often than the RCON poll.
        $schedule->call(fn () => app(LogTailService::class)->tail())
            ->name('server:tail-logs')
            ->everyTwoSeconds()
            ->withoutOverlapping();

        // Ambiguity resolution #2: bounded storage, no long-term
        // retention — prune ServerSample/PlayerEvent/ConsoleEntry daily.
        $schedule->command(PruneServerObservationData::class)->daily();
    })
    ->create();
