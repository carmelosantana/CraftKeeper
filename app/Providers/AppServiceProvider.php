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
use App\Http\Controllers\E2eResetController;
use App\Http\Controllers\PluginController;
use App\Models\Secret;
use App\Models\Setting;
use App\Operations\Handlers\ConfigApplyHandler;
use App\Operations\Handlers\ConfigRestoreHandler;
use App\Operations\Handlers\PluginOperationHandler;
use App\Operations\Handlers\RconCommandHandler;
use App\Operations\Handlers\ServerStopHandler;
use App\Operations\OperationHandlerRegistry;
use App\Testing\E2eFixturePluginSource;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        // handlers here; Task 10 added RconCommandHandler/ServerStopHandler
        // the same way; Task 15 registers PluginOperationHandler (a single
        // handler supporting all five plugin.* types — see its own
        // supports()) the same way too. See App\Operations\
        // OperationHandlerRegistry's docblock for the full extension
        // convention.
        $this->app->tag([
            ConfigApplyHandler::class,
            ConfigRestoreHandler::class,
            RconCommandHandler::class,
            ServerStopHandler::class,
            PluginOperationHandler::class,
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
        // docblock — never on source registration order), but IS
        // significant to App\Http\Controllers\PluginController::
        // resolveSource() (first match by PluginProvenance wins) — which
        // is exactly why the e2e-only substitution below REPLACES
        // CraftKeeperCatalogSource rather than adding a second
        // PluginProvenance::Catalog source alongside it (two sources
        // answering for the same key would make which one resolves()
        // install/update requests depend on registration order, an
        // ambiguity this avoids entirely).
        //
        // App\Testing\E2eFixturePluginSource is substituted for the real
        // CraftKeeperCatalogSource ONLY under the IDENTICAL environment +
        // E2E_TESTING flag guard every other e2e-only surface in this
        // application uses (App\Http\Controllers\E2eResetController::
        // allowed()) — never in production, and never in the PHP test
        // suite (which fakes HTTP directly instead). It exists so
        // Playwright, which drives a real running server with no
        // Http::fake() available, can install/update a real, same-origin,
        // deterministic release through the actual UI — see that class's
        // docblock.
        $this->app->tag([
            E2eResetController::allowed() ? E2eFixturePluginSource::class : CraftKeeperCatalogSource::class,
            HangarSource::class,
            ModrinthSource::class,
        ], 'catalog.source');

        $this->app->when(UnifiedCatalogService::class)
            ->needs('$sources')
            ->give(fn ($app) => $app->tagged('catalog.source'));

        // Task 15's App\Http\Controllers\PluginController resolves a
        // SPECIFIC source by identity (never trusting a client-supplied
        // download URL/checksum — see its class docblock), so it needs
        // the same tagged set UnifiedCatalogService gets, via the same
        // contextual-binding convention.
        $this->app->when(PluginController::class)
            ->needs('$sources')
            ->give(fn ($app) => $app->tagged('catalog.source'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApiRateLimiting();
    }

    /**
     * Task 17's ambiguity resolution #4: "429 for throttling" on /api/v1.
     * Keyed by the authenticated PERSONAL ACCESS TOKEN id when one is
     * present (so two tokens belonging to the same admin never share a
     * bucket), falling back to the client IP for the rare case a request
     * reaches this limiter unauthenticated (e.g. a request with no token
     * at all, which App\Http\Middleware\EnsureApiScope would reject
     * anyway, but the 'throttle:api' middleware in bootstrap/app.php's
     * 'api' group runs first).
     */
    protected function configureApiRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user('sanctum');
            $tokenId = $user?->currentAccessToken()->id ?? null;

            return Limit::perMinute(120)->by($tokenId ?? $request->ip());
        });
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
