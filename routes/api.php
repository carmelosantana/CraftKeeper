<?php

use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\OperationController;
use App\Http\Controllers\Api\V1\PluginController;
use App\Http\Controllers\Api\V1\ServerController;
use Illuminate\Support\Facades\Route;

/**
 * CraftKeeper's versioned, scoped REST API — Task 17. Every route below is
 * gated by App\Http\Middleware\EnsureApiScope with the EXACT scope(s)
 * named on that route (see App\Support\ApiScope for the fixed, closed
 * enum); a personal access token missing the scope gets 403, never a
 * silent downgrade — see that middleware's own docblock for why a
 * first-party browser session can't be used to bypass this.
 *
 * Every route's ->name() below is ALSO its documented OpenAPI
 * `operationId` in openapi.yaml (repo root) — tests/Contract/Api/
 * OpenApiTest.php compares the two directions (every registered route is
 * documented, every documented route is registered) using exactly this
 * identity, so the route name and the operationId can never silently
 * drift apart.
 *
 * NO route here can ever approve a mutation. `config:apply`
 * (ConfigController::apply()) only ever EXECUTES a config.apply/
 * config.restore operation a human already approved through the session-
 * authenticated web UI; see app/Policies/ApiOperationPolicy.php and
 * docs/architecture/decisions.md's Task 17 entry for the full
 * reconciliation. There is no approve endpoint, and no scope that could
 * reach one, anywhere in this file.
 *
 * This file itself only ever adds the `v1` segment — bootstrap/app.php's
 * `withRouting(api: ...)` already wraps every route here in the 'api'
 * middleware group and an outer `/api` prefix, so the full path for e.g.
 * `getServerStatus` below is `/api/v1/server/status`.
 */
Route::prefix('v1')->group(function () {
    Route::get('server/status', [ServerController::class, 'status'])
        ->middleware('scope:server:read')
        ->name('getServerStatus');

    Route::prefix('config')->group(function () {
        // Literal-prefixed routes registered before the `{path}` wildcard
        // (which accepts slashes via ->where('path', '.*')) — mirrors the
        // web App\Http\Controllers\ConfigController's own convention.
        Route::get('files', [ConfigController::class, 'index'])
            ->middleware('scope:config:read')
            ->name('listConfigFiles');

        Route::get('proposals', [ConfigController::class, 'listProposals'])
            ->middleware('scope:config:read')
            ->name('listConfigProposals');

        Route::post('proposals', [ConfigController::class, 'propose'])
            ->middleware('scope:config:propose')
            ->name('createConfigProposal');

        Route::post('proposals/{operation}/apply', [ConfigController::class, 'apply'])
            ->middleware('scope:config:apply')
            ->name('applyConfigProposal');

        Route::get('proposals/{operation}', [ConfigController::class, 'showProposal'])
            ->middleware('scope:config:read')
            ->name('getConfigProposal');

        Route::get('files/{path}', [ConfigController::class, 'show'])
            ->where('path', '.*')
            ->middleware('scope:config:read')
            ->name('getConfigFile');
    });

    Route::prefix('plugins')->group(function () {
        Route::get('/', [PluginController::class, 'index'])
            ->middleware('scope:plugins:read')
            ->name('listPlugins');

        Route::post('{filename}/disable', [PluginController::class, 'disable'])
            ->middleware('scope:plugins:manage')
            ->name('disablePlugin');

        Route::post('{filename}/remove', [PluginController::class, 'remove'])
            ->middleware('scope:plugins:manage')
            ->name('removePlugin');

        Route::get('{filename}', [PluginController::class, 'show'])
            ->middleware('scope:plugins:read')
            ->name('getPlugin');
    });

    Route::prefix('operations')->group(function () {
        Route::get('/', [OperationController::class, 'index'])
            ->middleware('scope:activity:read')
            ->name('listOperations');

        // Gated by an "any of" pair — a command's own risk classification
        // decides whether rcon:safe alone suffices, or rcon:admin is
        // required; see OperationController::createRconCommand()'s own
        // docblock.
        Route::post('rcon-commands', [OperationController::class, 'createRconCommand'])
            ->middleware('scope:rcon:safe,rcon:admin')
            ->name('createRconCommand');

        Route::get('{operation}', [OperationController::class, 'show'])
            ->middleware('scope:activity:read')
            ->name('getOperation');
    });
});
