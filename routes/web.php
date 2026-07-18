<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\Integrations\ApiTokenController;
use App\Http\Controllers\Integrations\McpGrantController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PluginController;
use App\Http\Controllers\ServerController;
use App\Http\Middleware\RequireInstallation;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/up', HealthController::class)
    ->withoutMiddleware([
        StartSession::class,
        EncryptCookies::class,
        PreventRequestForgery::class,
        ShareErrorsFromSession::class,
    ])
    ->name('health');

Route::inertia('/', 'welcome')->name('home');

// Public: Task 3's design-system showcase must be reachable without login
// (auth ships in Task 4) so the AppShell/token gallery can be reviewed and
// exercised by e2e tests ahead of any real, authenticated feature page.
Route::inertia('design-system', 'DesignSystem')->name('design-system');

// First-run setup wizard. The welcome screen and admin-creation endpoint
// vanish for good (404, not a redirect) the instant CraftKeeper's single
// admin account exists — see RequireInstallation and
// App\Support\InstallationState. There is no un-gated public registration
// route anywhere else in the app.
//
// Deliberately no 'guest' middleware here: "not installed" already implies
// no one can possibly be authenticated (installed === a user row exists),
// so it would be redundant — and ordering it before RequireInstallation
// would be actively wrong, since a request that's authenticated *because*
// this very endpoint just logged it in must still 404 on a replay, not get
// redirected to /dashboard by 'guest' before RequireInstallation ever runs.
Route::middleware([RequireInstallation::class.':not-installed'])->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'welcome'])->name('onboarding.welcome');
    Route::post('onboarding/admin', [OnboardingController::class, 'storeAdmin'])->name('onboarding.admin');
});

// The remaining onboarding steps (Minecraft directory, RCON, optional AI
// provider, optional analytics, completion) only make sense once the
// admin created above is signed in, so they're gated on 'auth' rather
// than installation state.
Route::middleware(['auth'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::get('server', [OnboardingController::class, 'server'])->name('server');
    Route::post('server', [OnboardingController::class, 'storeServer'])->name('server.store');
    Route::get('rcon', [OnboardingController::class, 'rcon'])->name('rcon');
    Route::post('rcon', [OnboardingController::class, 'storeRcon'])->name('rcon.store');
    Route::get('ai', [OnboardingController::class, 'ai'])->name('ai');
    Route::post('ai', [OnboardingController::class, 'storeAi'])->name('ai.store');
    Route::get('analytics', [OnboardingController::class, 'analytics'])->name('analytics');
    Route::post('analytics', [OnboardingController::class, 'storeAnalytics'])->name('analytics.store');
    Route::get('complete', [OnboardingController::class, 'complete'])->name('complete');
});

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Task 12: the server operations workspace.
    Route::get('overview', [OverviewController::class, 'index'])->name('overview');

    Route::prefix('server')->name('server.')->group(function () {
        Route::get('/', [ServerController::class, 'index'])->name('index');
        Route::get('players', [ServerController::class, 'players'])->name('players');

        // Literal-prefixed console routes registered before nothing
        // wildcard-shaped lives under this prefix — matches Task 9's
        // ConfigController convention of ordering specific routes ahead
        // of any catch-all, even though console has no catch-all today.
        Route::get('console', [ConsoleController::class, 'index'])->name('console');
        Route::post('console', [ConsoleController::class, 'compose'])->name('console.compose');
        Route::post('console/propose', [ConsoleController::class, 'propose'])->name('console.propose');
        Route::post('console/run', [ConsoleController::class, 'run'])->name('console.run');
        Route::post('console/actions/{key}', [ConsoleController::class, 'runSafeAction'])->name('console.actions.run');
        Route::post('console/operations/{operation}/approve', [ConsoleController::class, 'approve'])->name('console.approve');
        Route::post('console/operations/{operation}/reject', [ConsoleController::class, 'reject'])->name('console.reject');

        Route::get('logs', [LogController::class, 'index'])->name('logs');
        Route::get('logs/download', [LogController::class, 'download'])->name('logs.download');
    });

    Route::get('activity', [ActivityController::class, 'index'])->name('activity');

    // Task 17: the /api/v1 scoped-token management page. Session-
    // authenticated (like every other route in this group) — creating or
    // revoking a token is a human, web-console-only action, never
    // something reachable through /api/v1 itself. `openapi.yaml` is
    // served alongside it for the "link to the OpenAPI docs" requirement;
    // it is documentation, not a credential, so it needs no scope of its
    // own — only the same session auth every other page in this group
    // already requires.
    //
    // Task 19: `/integrations` now renders the real overview page
    // (Connected/Disabled/Degraded/Misconfigured for all ten
    // integrations — App\Support\IntegrationHealthChecker) instead of
    // redirecting straight to the API token page, which is what stood in
    // for it before this task built the rest of the Integrations surface.
    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('integrations/test/{key}', [IntegrationController::class, 'test'])->name('integrations.test');

    Route::prefix('integrations')->name('integrations.')->group(function () {
        Route::get('api', [ApiTokenController::class, 'index'])->name('api');
        Route::post('api/tokens', [ApiTokenController::class, 'store'])->name('api.tokens.store');
        Route::delete('api/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api.tokens.destroy');

        // Task 18: the MCP OAuth integration management page — connection
        // URL, authorization state, exact capabilities/scopes, last used,
        // expiry, revoke, and recent calls. Session-authenticated like
        // every other route in this group; this page never itself calls
        // /mcp/craftkeeper or any MCP tool/resource — it only manages the
        // App\Models\McpGrant/Laravel\Passport\Client rows those calls are
        // later authorized against.
        Route::get('mcp', [McpGrantController::class, 'index'])->name('mcp');
        Route::post('mcp/grants', [McpGrantController::class, 'store'])->name('mcp.grants.store');
        Route::delete('mcp/grants/{grant}', [McpGrantController::class, 'destroy'])->name('mcp.grants.destroy');
    });

    Route::get('openapi.yaml', fn () => response()->file(base_path('openapi.yaml'), ['Content-Type' => 'text/yaml']))
        ->name('openapi');

    // Task 16: the optional AI assistant. AI being disabled/unavailable
    // never affects any other route in this group — see App\Ai\AiManager
    // and tests/Feature/Ai/AiUnavailableTest.php.
    Route::get('assistant', [AssistantController::class, 'index'])->name('assistant');
    Route::prefix('assistant')->name('assistant.')->group(function () {
        Route::post('conversations', [AssistantController::class, 'store'])->name('conversations.store');
        Route::post('conversations/{conversation}/messages', [AssistantController::class, 'message'])->name('messages.store');
    });

    // Task 9: configuration inventory + editor. Literal-prefixed routes
    // (operations/*, revisions/*, history/*) are registered BEFORE the two
    // `{path}` wildcards (which accept slashes, via `where('path', '.*')`,
    // so a real Minecraft-relative path like "plugins/Geyser-Spigot/
    // config.yml" resolves as one route parameter) so none of them can
    // ever be swallowed by the wildcard — a real config file would need to
    // be literally named e.g. "operations/<uuid>/approve" to collide, which
    // ConfigDiscoveryService could never produce from a real Minecraft
    // install.
    Route::prefix('configurations')->name('configurations.')->group(function () {
        Route::get('/', [ConfigController::class, 'index'])->name('index');
        Route::post('operations/{operation}/approve', [ConfigController::class, 'approve'])->name('approve');
        Route::post('operations/{operation}/reject', [ConfigController::class, 'reject'])->name('reject');
        Route::post('revisions/{revision}/restore', [ConfigController::class, 'restore'])->name('restore');
        Route::get('history/{path}', [ConfigController::class, 'history'])->where('path', '.*')->name('history');
        Route::post('{path}', [ConfigController::class, 'propose'])->where('path', '.*')->name('propose');
        Route::get('{path}', [ConfigController::class, 'edit'])->where('path', '.*')->name('edit');
    });

    // Task 15: plugin discovery, installed inventory/detail, manual
    // upload, and the full plugin.* operation lifecycle. Literal-prefixed
    // routes (discover, upload, install, operations/*) are registered
    // BEFORE the two `{filename}` wildcards, matching Task 9's
    // ConfigController convention — a real installed plugin's filename
    // (App\Plugins\PluginInventoryService scans `plugins/` NON-recursively,
    // so a relative_path is always exactly "plugins/{filename}", never
    // containing another "/") would need to literally be named e.g.
    // "discover" or "operations" to collide, which no real .jar ever is.
    Route::prefix('plugins')->name('plugins.')->group(function () {
        Route::get('/', [PluginController::class, 'index'])->name('index');
        Route::get('discover', [PluginController::class, 'discover'])->name('discover');
        Route::get('upload', [PluginController::class, 'uploadForm'])->name('upload');
        Route::post('upload', [PluginController::class, 'uploadStore'])->name('upload.store');
        Route::post('upload/{token}/propose', [PluginController::class, 'uploadPropose'])->name('upload.propose');
        Route::post('install', [PluginController::class, 'proposeInstall'])->name('install');

        Route::get('operations/{operation}', [PluginController::class, 'operation'])->name('operations.show');
        Route::post('operations/{operation}/approve', [PluginController::class, 'approve'])->name('operations.approve');
        Route::post('operations/{operation}/reject', [PluginController::class, 'reject'])->name('operations.reject');
        Route::post('operations/{operation}/rollback', [PluginController::class, 'rollbackOperation'])->name('operations.rollback');

        Route::get('{filename}', [PluginController::class, 'show'])->name('show');
        Route::post('{filename}/update', [PluginController::class, 'proposeUpdate'])->name('update');
        Route::post('{filename}/disable', [PluginController::class, 'proposeDisable'])->name('disable');
        Route::post('{filename}/remove', [PluginController::class, 'proposeRemove'])->name('remove');
        Route::post('{filename}/rollback', [PluginController::class, 'proposeRollback'])->name('rollback');
    });
});

require __DIR__.'/settings.php';

// Test-only e2e reset endpoint — see routes/testing.php's own docblock for
// the production-safety guard. Required unconditionally; the route inside
// is registered only under a strict local/testing + env-flag guard.
require __DIR__.'/testing.php';
