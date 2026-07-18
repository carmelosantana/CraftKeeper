<?php

use App\Config\Exceptions\ConfigConflict;
use App\Console\Commands\PrunePluginRollbackArtifacts;
use App\Console\Commands\PruneServerObservationData;
use App\Console\Commands\SampleServerState;
use App\Http\Middleware\AssignApiCorrelationId;
use App\Http\Middleware\EnsureApiScope;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Server\LogTailService;
use App\Support\Api\ApiError;
use App\Support\Api\Exceptions\IdempotencyKeyConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // Task 17: routes/api.php only ever adds a `v1` segment — this
        // wraps it in the 'api' middleware group and an outer `/api`
        // prefix (Laravel's own convention), so its routes end up at
        // `/api/v1/...`.
        api: __DIR__.'/../routes/api.php',
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

        // Task 17's ambiguity resolution #4: "429 for throttling" — every
        // /api/v1 request is rate-limited under the 'api' RateLimiter
        // (defined in App\Providers\AppServiceProvider::configureApiRateLimiting()).
        // AssignApiCorrelationId runs FIRST so a correlation id is
        // attached (and echoed back) even on a throttled/unauthenticated/
        // scope-rejected request — see that middleware's own docblock.
        $middleware->api(prepend: [
            AssignApiCorrelationId::class,
        ]);
        $middleware->throttleApi();

        $middleware->alias([
            'scope' => EnsureApiScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every /api/v1 error below renders Task 17's one JSON error shape
        // — {code, message, details, correlation_id} — regardless of
        // which layer raised it. Scoped to `api/*` throughout so none of
        // this touches the existing web/Inertia error rendering (which
        // ConfigController/ConsoleController/PluginController already
        // handle their own way, e.g. ConfigConflict never even reaches
        // here for a web request — App\Http\Controllers\ConfigController::
        // propose() catches it itself).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 401, 'unauthenticated', 'A valid API token is required.');
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 403, 'forbidden', $e->getMessage() ?: 'This action is unauthorized.');
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 422, 'validation_failed', 'The given data was invalid.', $e->errors());
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 404, 'not_found', 'The requested resource was not found.');
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 404, 'not_found', 'The requested resource was not found.');
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 429, 'rate_limited', 'Too many requests. Please slow down and try again shortly.');
            }
        });

        $exceptions->render(function (ConfigConflict $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 409, 'stale_hash', $e->getMessage(), [
                    'expected_sha256' => $e->expectedSha256,
                    'actual_sha256' => $e->actualSha256,
                ]);
            }
        });

        $exceptions->render(function (IdempotencyKeyConflict $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 409, 'idempotency_key_conflict', $e->getMessage());
            }
        });

        // Any other HTTP-status-carrying exception (e.g. a plain
        // abort(4xx) this controller layer didn't already special-case)
        // still renders the shared shape, preserving its real status code.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, $e->getStatusCode(), 'http_error', $e->getMessage() ?: 'The request could not be completed.');
            }
        });

        // Last resort: any other exception on /api/v1 still renders the
        // shared shape, with a deliberately generic message (the real
        // exception is still logged normally) so a stack trace or
        // internal detail can never leak into a client response.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiError::response($request, 500, 'internal_error', 'An unexpected error occurred.');
            }
        });
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

        // Task 15's ambiguity resolution #3: keep 3 preserved plugin
        // rollback artifacts per plugin for 30 days, pruned daily.
        $schedule->command(PrunePluginRollbackArtifacts::class)->daily();
    })
    ->create();
