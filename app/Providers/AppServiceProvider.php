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
use App\Mcp\Support\McpScopeConsequences;
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
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Guards\TokenGuard;
use Laravel\Passport\Passport;

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

        // Deliberately called from register(), NOT boot(): Passport is an
        // auto-discovered package provider, so
        // Laravel\Passport\PassportServiceProvider::boot() —
        // which reads Passport::$deviceCodeGrantEnabled to decide whether
        // to register the GET /oauth/device route AT ALL (see vendor/
        // laravel/passport/routes/web.php) — runs BEFORE this class's own
        // boot() in Laravel's standard "package providers, then app
        // providers" ordering. Flipping the flag from THIS class's boot()
        // would be one phase too late: the route would already be
        // registered against the old (true) default. register() runs for
        // every provider before boot() runs for any provider, so setting
        // it here is guaranteed to land before Passport reads it.
        $this->configurePassportGrantTypes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->configureApiRateLimiting();
        $this->configurePassportConsent();
        $this->registerMcpRoutes();
    }

    /**
     * Task 20: TRUSTED_PROXIES (config/craftkeeper.php `trusted_proxies`)
     * — comma-separated IPs/CIDRs, the literal '*' to trust any proxy, or
     * unset/blank to trust none (the default; a no-op, identical to this
     * app's behavior before this task). Called from boot() rather than
     * via `Middleware::trustProxies()` in bootstrap/app.php because that
     * closure runs before config is loaded (see bootstrap/app.php's own
     * comment) — `Illuminate\Http\Middleware\TrustProxies::class` is
     * already unconditionally in the global middleware stack regardless
     * of whether this ever runs, so calling its `at()`/`withHeaders()`
     * static setters directly here is equivalent to, not a substitute
     * for, that fluent helper.
     *
     * Getting this right is what lets `$request->isSecure()` — HSTS
     * (App\Http\Middleware\SecurityHeaders), and SESSION_SECURE_COOKIE's
     * own null-means-auto-detect behavior (config/session.php, untouched
     * by this task) — correctly reflect an HTTPS-terminating reverse
     * proxy's `X-Forwarded-Proto` instead of the always-plain-HTTP
     * connection PHP-FPM/nginx see inside the container
     * (docker/nginx/default.conf never terminates TLS itself).
     */
    protected function configureTrustedProxies(): void
    {
        $trustedProxies = trim((string) config('craftkeeper.trusted_proxies'));

        if ($trustedProxies === '') {
            return;
        }

        TrustProxies::at($trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)));
    }

    /**
     * Task 18's ambiguity resolution #2: authorization-code + PKCE only.
     * `$passwordGrantEnabled`/`$implicitGrantEnabled` already default
     * false in this Passport version — set explicitly anyway so the
     * restriction is documented in code, not just relied upon as an
     * upstream default that could silently change.
     * `$deviceCodeGrantEnabled` DOES default true (and, unlike the other
     * two, its route only registers when true — see vendor/laravel/
     * passport/routes/web.php), so this MUST explicitly disable it; a
     * device-code flow is a distinct, separate authorization flow that is
     * not "authorization-code + PKCE". `client_credentials` is enabled
     * server-wide by Passport itself unconditionally and cannot be turned
     * off globally, but is closed in practice: every OAuth client this
     * application ever creates (App\Http\Controllers\Integrations\
     * McpGrantController::store(), via Laravel\Passport\ClientRepository::
     * createAuthorizationCodeGrantClient()) is stored with
     * grant_types = ['authorization_code', 'refresh_token'] ONLY, and no
     * route in this application can ever create a client any other way
     * (`$registersJsonApiRoutes` stays false, closing Passport's own
     * client self-service API too) — see
     * tests/Feature/Mcp/McpOAuthTest.php.
     *
     * See boot()'s configurePassportConsent() for the scope/consent-view
     * half of this same ambiguity resolution — split across register()/
     * boot() purely for the ordering reason above, not a logical one.
     */
    protected function configurePassportGrantTypes(): void
    {
        Passport::$passwordGrantEnabled = false;
        Passport::$implicitGrantEnabled = false;
        Passport::$deviceCodeGrantEnabled = false;
        Passport::$registersJsonApiRoutes = false;
    }

    /**
     * `Passport::tokensCan()` registers the SAME scope strings as
     * App\Support\ApiScope (Task 17), each with a consent-screen
     * description that names its concrete consequence
     * (App\Mcp\Support\McpScopeConsequences) — rendered by
     * resources/views/mcp/authorize.blade.php, which
     * `authorizationView()` points Passport's stock `/oauth/authorize`
     * consent screen at. Safe to run from boot() (unlike the grant-type
     * flags above): nothing reads these until an actual `/oauth/authorize`
     * request is handled, long after every provider has booted.
     */
    protected function configurePassportConsent(): void
    {
        Passport::tokensCan(McpScopeConsequences::map());

        Passport::authorizationView('mcp.authorize');
    }

    /**
     * Loads routes/mcp.php explicitly — see that file's own docblock for
     * why this happens here (a dedicated, non-'web'/'api' route group)
     * rather than via laravel/mcp's own routes/ai.php auto-discovery.
     * Mirrors Laravel\Mcp\Server\McpServiceProvider::registerRoutes()'s
     * own file-exists + route-cache guard for routes/ai.php.
     */
    protected function registerMcpRoutes(): void
    {
        $path = base_path('routes/mcp.php');

        if (! file_exists($path)) {
            return;
        }

        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        Route::group([], $path);
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
     *
     * Task 20 adds four more named limiters alongside it, all keyed the
     * same way (the authenticated admin's user id when there is a
     * session, else the client IP — there is only ever one admin account
     * in this app, so a per-user key here is mostly documentation of
     * intent, but keeps every limiter's key derivation identical and
     * future-proof):
     *
     * - `ai` — the assistant's conversation/message endpoints
     *   (routes/web.php `assistant/*`). A single message can trigger a
     *   real outbound call to a paid hosted provider, so this is
     *   deliberately tighter than the general web traffic these routes
     *   otherwise share no throttling with at all.
     * - `uploads` — plugin JAR upload endpoints (routes/web.php
     *   `plugins/upload`, `plugins/upload/{token}/propose`). Bounds
     *   abuse of the (already size- and time-bounded, see
     *   config/craftkeeper.php `plugins.*`) quarantine/hash pipeline.
     * - `tokens` — API personal-access-token and MCP OAuth-grant
     *   issuance (routes/web.php `integrations/api/tokens`,
     *   `integrations/mcp/grants`). Credential-issuance endpoints are a
     *   classic brute-force/abuse target even behind an authenticated
     *   session.
     * - `mcp` — the entire MCP JSON-RPC endpoint (routes/mcp.php).
     *   Higher than the other three: a single legitimate MCP client
     *   session can reasonably make several tool/resource calls per
     *   user action (Task 18/20's own smoke/integration tests exercise
     *   more than one call per scenario).
     */
    protected function configureApiRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user('sanctum');
            $tokenId = $user?->currentAccessToken()->id ?? null;

            return Limit::perMinute(120)->by($tokenId ?? $request->ip());
        });

        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('tokens', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(60)
            ->by($this->mcpClientIdForRateLimit() ?? $request->ip()));
    }

    /**
     * Same resolution App\Mcp\Support\McpGuard itself uses (see that
     * class's docblock for exactly why): the Passport OAUTH CLIENT
     * identity via TokenGuard::client(), not a currentAccessToken() call
     * that doesn't statically exist on this app's Sanctum-templated User
     * model.
     */
    protected function mcpClientIdForRateLimit(): int|string|null
    {
        return $this->mcpClientIdForGuard(Auth::guard('passport'));
    }

    /**
     * Takes the plain `Illuminate\Contracts\Auth\Guard` interface —
     * exactly App\Mcp\Support\McpGuard::grantForGuard()'s own trick, and
     * for the identical reason (see that method's docblock in full):
     * Larastan's Auth reflection extension speculatively narrows
     * `Auth::guard('passport')` to `Illuminate\Auth\RequestGuard` inline,
     * with no way to see that `Laravel\Passport\PassportServiceProvider`
     * registers the 'passport' driver via `Auth::extend()` to actually
     * construct a `Laravel\Passport\Guards\TokenGuard` at runtime —
     * making a direct `instanceof TokenGuard` check at the call site
     * misreport as "always false". Crossing a function boundary with an
     * explicitly, correctly typed parameter resets analysis to that
     * DECLARED (interface) type instead.
     */
    protected function mcpClientIdForGuard(Guard $guard): int|string|null
    {
        if (! $guard instanceof TokenGuard) {
            return null;
        }

        return $guard->client()?->getKey();
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
