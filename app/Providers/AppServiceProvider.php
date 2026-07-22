<?php

namespace App\Providers;

use App\Catalog\Sources\CraftKeeperCatalogSource;
use App\Catalog\Sources\HangarSource;
use App\Catalog\Sources\ModrinthSource;
use App\Catalog\UnifiedCatalogService;
use App\Console\MinecraftRconClient;
use App\Console\PersistentRconClient;
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

        // The SAME client, configured to hold one authenticated
        // connection open across many commands. Only
        // App\Console\Commands\WatchServerState resolves this: it is the
        // one caller that runs commands on a loop, and every connection
        // it avoids opening is two fewer INFO lines in the operator's own
        // latest.log (see App\Console\MinecraftRconClient's docblock for
        // the measurements). Bound separately rather than swapping the
        // binding above so the rare, user-issued, audited commands keep
        // the connection-per-command default.
        //
        // Deliberately NOT a singleton: `bind` still hands the one
        // resolving command a single instance for its whole lifetime
        // (it resolves once, in handle()), while keeping the host/port/
        // password read from the encrypted Setting/Secret store at
        // resolution time rather than frozen at first boot.
        $this->app->bind(PersistentRconClient::class, fn (): PersistentRconClient => new MinecraftRconClient(
            new StreamRconTransport,
            Setting::get('rcon.host') ?? '127.0.0.1',
            (int) (Setting::get('rcon.port') ?? 25575),
            Secret::get('rcon.password') ?? '',
            persistent: true,
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
     * Host patterns Illuminate\Http\Middleware\TrustHosts will accept.
     *
     * Without this, any client-supplied Host header is reflected straight
     * into generated absolute URLs:
     *
     *   curl -H "Host: evil.example.com" http://127.0.0.1:8123/
     *   -> Location: http://evil.example.com/onboarding
     *
     * That matters here because password-reset and email-verification links
     * are absolute URLs built from the incoming request. A poisoned Host
     * therefore mails a working, correctly-signed reset link pointing at an
     * attacker's domain. It also lets a shared cache in front of the app be
     * poisoned. nginx cannot prevent it — docker/nginx/default.conf serves
     * `server_name _` because the container is published on whatever
     * hostname the operator chooses, so the app is the portable place to
     * decide which of those names are real.
     *
     * Passed as a closure to Middleware::trustHosts() in bootstrap/app.php:
     * that closure runs before configuration is loaded (see that file's own
     * comment on TRUSTED_PROXIES), but TrustHosts resolves a callable lazily
     * per request, by which point config() exists.
     *
     * What gets trusted:
     *
     *   - the host of APP_URL, which is the operator's own declaration of
     *     where this installation lives;
     *   - every entry in TRUSTED_HOSTS, for installations reachable under
     *     more than one name;
     *   - the loopback literals. A link pointing at localhost or 127.0.0.1
     *     resolves to the victim's own machine, so it cannot deliver anyone
     *     to an attacker — and trusting them keeps `docker run -p 8123:8080`
     *     and the image's own health check working.
     *
     * Returning an empty array disables the check (Symfony only validates
     * when at least one pattern is set). That is deliberate for the
     * unconfigured case: with APP_URL still on Laravel's `http://localhost`
     * default and no TRUSTED_HOSTS, the operator has not told us any real
     * hostname, and enforcing would reject the LAN address or container
     * hostname they are almost certainly using. Guessing wrong there breaks
     * a working install to defend a threat that only exists once the app is
     * actually published somewhere. Set APP_URL — as compose.example.yml
     * does — and the check turns itself on.
     *
     * Patterns are anchored regexes, not literals: Symfony wraps each one as
     * `{pattern}i` and runs preg_match, so a bare "localhost" would also
     * match the attacker-controlled "evil.localhost.example.com".
     *
     * @return array<int, string>
     */
    public static function trustedHostPatterns(): array
    {
        $configured = [];

        if ($appHost = parse_url((string) config('app.url'), PHP_URL_HOST)) {
            $configured[] = $appHost;
        }

        foreach (explode(',', (string) config('craftkeeper.trusted_hosts')) as $host) {
            if (($host = trim($host)) !== '') {
                $configured[] = $host;
            }
        }

        // "localhost" alone is the framework default, not a declaration, so
        // it does not by itself switch enforcement on.
        $declared = array_values(array_diff(array_unique($configured), ['localhost']));

        if ($declared === []) {
            return [];
        }

        $hosts = array_unique([...$declared, 'localhost', '127.0.0.1', '::1', '[::1]']);

        return array_map(
            static fn (string $host): string => '^'.preg_quote($host).'$',
            array_values($hosts),
        );
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
     *
     * Whole-branch fix pass adds a sixth:
     *
     * - `console` — the web console's execute/approve routes
     *   (routes/web.php `server/console/run`,
     *   `server/console/actions/{key}`,
     *   `server/console/operations/{operation}/approve`). These are the
     *   ONLY web-console routes that can put a real RCON command on the
     *   wire (approve() is additionally the only one that can do so for
     *   an Elevated command — see App\Http\Controllers\ConsoleController's
     *   own docblock) and, before this fix, were the single least-
     *   restricted path to the highest-consequence action in the whole
     *   app — MCP's functionally equivalent run_safe_rcon tool is capped
     *   at 60/min and /api/v1 as a whole at 120/min, while these routes
     *   had no limiter at all. 30/min is deliberately generous for a
     *   human clicking/typing in a browser (roughly one command every two
     *   seconds sustained for a full minute) while still bounding a
     *   scripted or compromised-session flood.
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

        RateLimiter::for('console', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
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

        // Length and breach-checking, not composition rules.
        //
        // This previously demanded 12 characters with mixed case, numbers AND
        // symbols, which made the one account this install will ever have
        // needlessly painful to create — the first thing a new operator hits,
        // on the onboarding step they cannot skip.
        //
        // Dropping the composition requirements is not a straight weakening.
        // NIST SP 800-63B specifically advises against them: they push people
        // toward predictable shapes ("Password1!") while adding little real
        // entropy. What actually helps is length, kept here, and refusing
        // passwords already known to be breached, also kept.
        //
        // uncompromised() sends only the first five characters of the
        // password's SHA-1 to api.pwnedpasswords.com (k-anonymity — the
        // password itself never leaves this machine) and fails open when
        // that call cannot be made, so an air-gapped install still works.
        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(10)->uncompromised()
            : null,
        );
    }
}
