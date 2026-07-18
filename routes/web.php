<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OnboardingController;
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
});

require __DIR__.'/settings.php';
