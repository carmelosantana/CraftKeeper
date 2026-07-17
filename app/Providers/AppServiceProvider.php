<?php

namespace App\Providers;

use App\Filesystem\LocalMinecraftFilesystem;
use App\Filesystem\MinecraftFilesystem;
use App\Operations\OperationHandlerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // OperationHandlerRegistry is built from every service tagged
        // 'operation.handler'. No concrete handler exists yet (Task 5) —
        // Tasks 8, 10, and 15 register theirs with, e.g.:
        //
        //   $this->app->tag(ConfigApplyHandler::class, 'operation.handler');
        //
        // See App\Operations\OperationHandlerRegistry's docblock for the
        // full extension convention.
        $this->app->singleton(OperationHandlerRegistry::class, fn ($app) => new OperationHandlerRegistry(
            $app->tagged('operation.handler'),
        ));

        // The single, contained filesystem boundary beneath every read/
        // write of the mounted Minecraft directory — see
        // App\Filesystem\MinecraftPath's docblock for the containment
        // guarantee this binding ultimately rests on.
        $this->app->bind(MinecraftFilesystem::class, LocalMinecraftFilesystem::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
