<?php

namespace App\Catalog;

use App\Models\CatalogCacheEntry;
use App\Plugins\PluginProvenance;
use Illuminate\Support\Carbon;

/**
 * Thin persistence wrapper around App\Models\CatalogCacheEntry
 * implementing the brief's two caching rules:
 *
 * - `isFresh()`: a row younger than `fresh_until` (15 minutes by
 *   default, config('catalog.cache.page_fresh_minutes')) can be served
 *   directly, with NO live fetch attempted at all.
 * - `isWithinRetention()`: a row younger than `expires_at` (7 days by
 *   default, config('catalog.cache.retention_days')) can still be
 *   served as a labeled, stale fallback if a live fetch is attempted
 *   and fails — the brief's stale-while-error requirement. A row past
 *   `expires_at` is treated as if it does not exist, even though the
 *   database row itself is only replaced (never proactively deleted) on
 *   the next successful `put()` for that key.
 */
final class CatalogCache
{
    public function find(string $cacheKey): ?CatalogCacheEntry
    {
        return CatalogCacheEntry::query()->where('cache_key', $cacheKey)->first();
    }

    public function isFresh(CatalogCacheEntry $entry): bool
    {
        return Carbon::now()->lt($entry->fresh_until);
    }

    public function isWithinRetention(CatalogCacheEntry $entry): bool
    {
        return Carbon::now()->lt($entry->expires_at);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function put(
        string $cacheKey,
        PluginProvenance $source,
        string $kind,
        array $payload,
        ?string $etag,
        ?string $lastModified,
    ): CatalogCacheEntry {
        $freshMinutes = (int) config('catalog.cache.page_fresh_minutes');
        $retentionDays = (int) config('catalog.cache.retention_days');

        return CatalogCacheEntry::query()->updateOrCreate(
            ['cache_key' => $cacheKey],
            [
                'source' => $source->value,
                'kind' => $kind,
                'payload' => $payload,
                'etag' => $etag,
                'last_modified' => $lastModified,
                'fresh_until' => Carbon::now()->addMinutes($freshMinutes),
                'expires_at' => Carbon::now()->addDays($retentionDays),
            ],
        );
    }

    /**
     * A 304 confirms the previously cached body is still current — no
     * re-normalization needed, just extend the freshness/retention
     * window so the NEXT fetch attempt doesn't happen for another 15
     * minutes.
     */
    public function touchFreshness(CatalogCacheEntry $entry): void
    {
        $freshMinutes = (int) config('catalog.cache.page_fresh_minutes');
        $retentionDays = (int) config('catalog.cache.retention_days');

        $entry->update([
            'fresh_until' => Carbon::now()->addMinutes($freshMinutes),
            'expires_at' => Carbon::now()->addDays($retentionDays),
        ]);
    }
}
