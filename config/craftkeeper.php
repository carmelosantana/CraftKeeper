<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Root
    |--------------------------------------------------------------------------
    |
    | Absolute path to the writable directory where CraftKeeper stores its
    | own state, such as the SQLite database and future snapshots/audit
    | data. The container sets DATA_ROOT=/data; local development and the
    | test suite fall back to a directory inside Laravel's storage path so
    | neither depends on a path that only exists inside Docker.
    |
    */

    'data_root' => env('DATA_ROOT', storage_path('craftkeeper')),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies (Task 20)
    |--------------------------------------------------------------------------
    |
    | Read here (not directly via env() in bootstrap/app.php, which would
    | return null once config is cached in production) and passed to
    | Illuminate\Foundation\Configuration\Middleware::trustProxies() at
    | boot. See .env.example's TRUSTED_PROXIES comment for the exact
    | semantics (comma-separated IPs/CIDRs, '*' to trust any proxy, or
    | unset to trust none — the default, and a no-op change in behavior).
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Hosts
    |--------------------------------------------------------------------------
    |
    | Extra hostnames this installation may legitimately be reached on, beyond
    | the host in APP_URL. Comma-separated, exact hostnames (no scheme, no
    | port) — e.g. "craftkeeper.lan,192.168.1.50".
    |
    | Only needed when CraftKeeper answers on more than one name: a LAN address
    | as well as a proxied domain, say. Leave it unset if APP_URL is the only
    | way in.
    |
    | See App\Providers\AppServiceProvider::trustedHostPatterns() for exactly
    | which hosts end up trusted and when the check is enforced at all.
    |
    */

    'trusted_hosts' => env('TRUSTED_HOSTS'),

    /*
    |--------------------------------------------------------------------------
    | Minecraft Root
    |--------------------------------------------------------------------------
    |
    | Absolute path to the mounted Minecraft server directory CraftKeeper is
    | allowed to read and write. This is the one and only root every
    | App\Filesystem\MinecraftPath canonically resolves against — nothing in
    | CraftKeeper may read or write outside of it. The container always sets
    | MINECRAFT_ROOT=/minecraft (see compose.example.yml); local development
    | and the test suite fall back to a directory inside Laravel's storage
    | path so neither depends on a path that only exists inside Docker. The
    | fallback directory is not created automatically — until it (or a real
    | MINECRAFT_ROOT) exists, every filesystem operation safely refuses to
    | resolve any path rather than operate against a phantom root.
    |
    */

    'minecraft_root' => env('MINECRAFT_ROOT', storage_path('craftkeeper/minecraft')),

    /*
    |--------------------------------------------------------------------------
    | E2E Testing Mode
    |--------------------------------------------------------------------------
    |
    | Gates the test-only database reset endpoint (routes/testing.php,
    | App\Http\Controllers\E2eResetController) that the Playwright suite
    | uses to give every e2e spec file its own deterministic, order-
    | independent baseline. This flag alone does not enable the route —
    | routes/testing.php also requires app()->environment(['local',
    | 'testing']), and the controller re-checks BOTH conditions itself and
    | 404s if either fails. Set ONLY by playwright.config.ts's
    | `webServer.env`; it must never appear in .env, .env.example,
    | compose.example.yml, or the Dockerfile. See
    | docs/architecture/decisions.md for the full rationale.
    |
    */

    'e2e_testing' => (bool) env('E2E_TESTING', false),

    /*
    |--------------------------------------------------------------------------
    | Plugin Lifecycle (Task 15)
    |--------------------------------------------------------------------------
    |
    | Bounds for App\Plugins\PluginDownloader/PluginUploadService's
    | quarantine step: a downloaded or uploaded artifact is streamed to
    | {data_root}/quarantine/{token} with its SHA-256 computed DURING
    | streaming, and refused outright past `max_artifact_bytes` — checked
    | BOTH against any declared Content-Length header (before a single body
    | byte is read) and against the running total of bytes actually read
    | (which a dishonest/absent Content-Length cannot bypass), mirroring
    | App\Plugins\JarInspector's own declared-size-then-actual-bytes
    | defense. `rollback_retention_*` bound App\Console\Commands\
    | PrunePluginRollbackArtifacts's daily prune of preserved JARs under
    | {data_root}/plugin-rollbacks.
    |
    */

    'plugins' => [
        'max_artifact_bytes' => (int) env('PLUGIN_MAX_ARTIFACT_BYTES', 100 * 1024 * 1024),
        'download_connect_timeout_seconds' => (int) env('PLUGIN_DOWNLOAD_CONNECT_TIMEOUT_SECONDS', 5),
        'download_timeout_seconds' => (int) env('PLUGIN_DOWNLOAD_TIMEOUT_SECONDS', 60),
        'rollback_retention_count' => (int) env('PLUGIN_ROLLBACK_RETENTION_COUNT', 3),
        'rollback_retention_days' => (int) env('PLUGIN_ROLLBACK_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional AI Assistant (Task 16)
    |--------------------------------------------------------------------------
    |
    | AI is entirely optional: App\Ai\AiManager::provider() returns null
    | whenever no provider is configured, or the configured one fails a
    | health check within these bounds — an outage here must never affect
    | /up, configuration, plugins, the API, or MCP. Health checks (and the
    | HTTP client every App\Ai\Providers\* constructs by default) use a
    | 2-second CONNECT timeout and a 5-second RESPONSE timeout, with no
    | request-path retries (carmelosantana/php-agents never wraps its
    | Symfony HttpClient in a RetryableHttpClient, so "no retries" is
    | already the default — these two settings are the only knobs that
    | matter). Provider connection details themselves (base URL, model,
    | and — for the hosted provider only — an API key) live in the
    | Setting/Secret store, not here — see App\Models\AiProviderConfiguration.
    |
    */

    'ai' => [
        'connect_timeout_seconds' => (float) env('AI_CONNECT_TIMEOUT_SECONDS', 2.0),
        'response_timeout_seconds' => (float) env('AI_RESPONSE_TIMEOUT_SECONDS', 5.0),
    ],

];
