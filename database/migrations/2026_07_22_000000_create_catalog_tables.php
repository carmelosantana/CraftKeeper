<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Two tables back Task 14's unified plugin catalog.
     *
     * `catalog_cache_entries` is the persistence layer for BOTH caching
     * concerns the brief requires: a 15-minute "fresh" normalized search
     * page per (source, query) — `fresh_until` — and up to 7 days of
     * stale-while-error retention beyond that — `expires_at`. A DB table
     * (rather than the `cache` store, which is `array`/ephemeral in
     * tests and may be flushed independently in production) is used
     * deliberately so the 7-day "last successful CraftKeeper Catalog"
     * retention survives a cache flush and is trivially inspectable/
     * testable. `etag`/`last_modified` back conditional (If-None-Match /
     * If-Modified-Since) revalidation on the next fetch attempt after
     * `fresh_until` — see App\Catalog\CatalogCache and
     * App\Catalog\Sources\AbstractPluginSource.
     *
     * `catalog_source_states` is the per-source health snapshot —
     * consecutive failure count and last success/error — that lets
     * App\Catalog\UnifiedCatalogService and (eventually) a Task 15
     * status UI answer "is Hangar currently degraded" without re-deriving
     * it from log lines. Updated on every live fetch attempt by
     * App\Catalog\CatalogSourceHealth.
     */
    public function up(): void
    {
        Schema::create('catalog_cache_entries', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key')->unique();
            // PluginProvenance value ('Catalog' | 'Hangar' | 'Modrinth').
            $table->string('source');
            // 'page' (a normalized, query-specific PluginSearchPage) or
            // 'catalog-snapshot' (CraftKeeperCatalogSource's whole last-
            // known-good document, independent of any one query).
            $table->string('kind');
            $table->json('payload');
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->timestamp('fresh_until');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('catalog_source_states', function (Blueprint $table) {
            $table->id();
            // PluginProvenance value; one row per source.
            $table->string('source')->unique();
            // 'ok' | 'degraded' | 'unavailable' — see App\Catalog\CatalogSourceHealth.
            $table->string('status');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_source_states');
        Schema::dropIfExists('catalog_cache_entries');
    }
};
