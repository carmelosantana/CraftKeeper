<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\SettingsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // Task 19: AppShell's "Settings" nav item already promises a real
    // `/settings` index (matching `/integrations`'s own overview page) —
    // this replaces the plain redirect to `/settings/profile` that stood
    // in for it before this task.
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Task 19: the four settings sections that had no page at all before
    // this task. Validated by hand where a secret (RCON password, AI API
    // key) is involved — see SettingsController's own docblock — so a
    // failed validation can never flash a plaintext secret into the
    // session, matching OnboardingController's identical precaution.
    Route::get('settings/server', [SettingsController::class, 'server'])->name('settings.server.edit');
    Route::put('settings/server', [SettingsController::class, 'updateServer'])->name('settings.server.update');

    Route::get('settings/ai', [SettingsController::class, 'ai'])->name('settings.ai.edit');
    Route::put('settings/ai', [SettingsController::class, 'updateAi'])->name('settings.ai.update');

    Route::get('settings/analytics', [SettingsController::class, 'analytics'])->name('settings.analytics.edit');
    Route::put('settings/analytics', [SettingsController::class, 'updateAnalytics'])->name('settings.analytics.update');

    Route::get('settings/advanced', [SettingsController::class, 'advanced'])->name('settings.advanced.edit');
    // GET, not POST: generating a fresh support bundle has no lasting
    // side effect on application state (App\Support\SupportBundleService
    // writes only a throwaway zip under {data_root}/support-bundles that
    // App\Http\Controllers\SettingsController::downloadSupportBundle()
    // deletes again immediately after the response finishes sending), so
    // this is a plain file download link like BackupController::download()
    // or the existing OpenAPI reference link, not a state-mutating action.
    Route::get('settings/advanced/support-bundle', [SettingsController::class, 'downloadSupportBundle'])->name('settings.advanced.support-bundle');

    // Backups get their own controller (file-producing, not a plain
    // settings form) — literal-prefixed routes registered before the
    // `{backup}` wildcards, matching this codebase's ConfigController/
    // PluginController convention (see routes/web.php's own comments).
    Route::get('settings/backups', [BackupController::class, 'index'])->name('settings.backups.index');
    Route::post('settings/backups', [BackupController::class, 'store'])->name('settings.backups.store');
    Route::get('settings/backups/{backup}/download', [BackupController::class, 'download'])->name('settings.backups.download');
    Route::delete('settings/backups/{backup}', [BackupController::class, 'destroy'])->name('settings.backups.destroy');
});

// No `.well-known/passkey-endpoints` discovery route: passkeys are
// disabled app-wide (see config/fortify.php and
// docs/architecture/decisions.md, Task 4), so advertising a passkey
// enrollment endpoint would be misleading.
