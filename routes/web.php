<?php

use App\Http\Controllers\HealthController;
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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
