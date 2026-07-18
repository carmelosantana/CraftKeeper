<?php

namespace App\Providers;

use App\Catalog\Sources\CraftKeeperCatalogSource;
use App\Catalog\Sources\HangarSource;
use App\Catalog\Sources\ModrinthSource;
use App\Catalog\UnifiedCatalogService;
use App\Console\MinecraftRconClient;
use App\Console\RconClient;
use App\Console\StreamRconTransport;
use App\Filesystem\LocalMinecraftFilesystem;
use App\Filesystem\MinecraftFilesystem;
use App\Models\Secret;
use App\Models\Setting;
use App\Operations\Handlers\ConfigApplyHandler;
use App\Operations\Handlers\ConfigRestoreHandler;
use App\Operations\Handlers\RconCommandHandler;
use App\Operations\Handlers\ServerStopHandler;
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
        // 'operation.handler'. Task 8 registered the first two concrete
        // handlers here; Task 10 adds RconCommandHandler/ServerStopHandler
        // the same way; Task 15 registers plugin.* handlers the same way
        // too. See App\Operations\OperationHandlerRegistry's docblock for
        // the full extension convention.
        $this->app->tag([
            ConfigApplyHandler::class,
            ConfigRestoreHandler::class,
            RconCommandHandler::class,
            ServerStopHandler::class,
        ], 'operation.handler');

        $this->app->singleton(OperationHandlerRegistry::class, fn ($app) => new OperationHandlerRegistry(
            $app->tagged('operation.handler'),
        ));

        // The single, contained filesystem boundary beneath every read/
        // write of the mounted Minecraft directory — see
        // App\Filesystem\MinecraftPath's docblock for the containment
        // guarantee this binding ultimately rests on.
        $this->app->bind(MinecraftFilesystem::class, LocalMinecraftFilesystem::class);

        // The real RconClient: a MinecraftRconClient wired to the real
        // StreamRconTransport, with host/port/password sourced from the
        // encrypted Setting/Secret store (Task 4) at resolution time —
        // never hard-coded, never logged. Constructing this does NOT open
        // a socket (that only happens inside RconClient::execute()); no
        // test in this application resolves RconClient from the
        // container — every RCON test injects
        // tests\fixtures\rcon\FakeRconTransport directly into a
        // hand-built MinecraftRconClient instead (Task 10's ambiguity
        // resolution #1), so this binding is never exercised by the
        // suite.
        $this->app->bind(RconClient::class, fn (): RconClient => new MinecraftRconClient(
            new StreamRconTransport,
            Setting::get('rcon.host') ?? '127.0.0.1',
            (int) (Setting::get('rcon.port') ?? 25575),
            Secret::get('rcon.password') ?? '',
        ));

        // Every App\Catalog\PluginSource adapter, tagged so
        // UnifiedCatalogService can be given all of them without
        // knowing the concrete list — the same tag-then-inject
        // convention as 'operation.handler' above. Order here is NOT
        // significant to search results (App\Catalog\UnifiedCatalogService
        // sorts deterministically on its own criteria — see its
        // docblock — never on source registration order).
        $this->app->tag([
            CraftKeeperCatalogSource::class,
            HangarSource::class,
            ModrinthSource::class,
        ], 'catalog.source');

        $this->app->when(UnifiedCatalogService::class)
            ->needs('$sources')
            ->give(fn ($app) => $app->tagged('catalog.source'));
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
