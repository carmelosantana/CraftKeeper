<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog Sources
    |--------------------------------------------------------------------------
    |
    | Base URLs for the three App\Catalog\PluginSource adapters. None of
    | these are ever contacted by the test suite (every test fakes the
    | HTTP layer via Http::fake()) — they only matter for real requests
    | in a running application. The Hangar/Modrinth defaults are their
    | real, documented public API bases (hangar.papermc.io/api-docs,
    | docs.modrinth.com/api); the CraftKeeper Catalog default points at
    | the independent carmelosantana/minecraft-plugin-catalog
    | repository's published JSON document — see
    | docs/architecture/plugin-catalog.md.
    |
    */

    'sources' => [
        'craftkeeper' => [
            'url' => env(
                'CATALOG_CRAFTKEEPER_URL',
                'https://raw.githubusercontent.com/carmelosantana/minecraft-plugin-catalog/main/catalog.json',
            ),
        ],
        'hangar' => [
            'base_url' => env('CATALOG_HANGAR_BASE_URL', 'https://hangar.papermc.io/api/v1'),
        ],
        'modrinth' => [
            'base_url' => env('CATALOG_MODRINTH_BASE_URL', 'https://api.modrinth.com/v2'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resilient HTTP Client
    |--------------------------------------------------------------------------
    |
    | Shared by every source via App\Catalog\Transport\CatalogHttpClient.
    | See that class's docblock for exactly how each of these is
    | enforced (timeouts, retry-on-idempotent-transient-errors-only,
    | user agent, response-size limit).
    |
    */

    'http' => [
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
        'retries' => 2,
        'retry_delay_ms' => 100,
        'max_response_bytes' => 5 * 1024 * 1024, // 5 MiB
        'user_agent' => 'CraftKeeper/1.0 (+https://github.com/carmelosantana/craftkeeper; plugin-catalog-client)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache / Retention
    |--------------------------------------------------------------------------
    |
    | `page_fresh_minutes` — how long a normalized, per-(source, query)
    | result may be served WITHOUT attempting a live fetch.
    | `retention_days` — how long a cache row remains eligible to be
    | served as a labeled, stale fallback AFTER a live fetch fails (the
    | brief's "retain the last successful CraftKeeper Catalog for 7
    | days" stale-while-error requirement — applied uniformly to every
    | source's cache rows, not only the CraftKeeper Catalog one, since
    | nothing about the mechanism is source-specific and every source
    | benefits identically from "cached results remain available" during
    | an outage; see docs/architecture/decisions.md).
    |
    */

    'cache' => [
        'page_fresh_minutes' => 15,
        'retention_days' => 7,
    ],

];
