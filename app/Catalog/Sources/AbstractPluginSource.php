<?php

namespace App\Catalog\Sources;

use App\Catalog\CatalogCache;
use App\Catalog\CatalogCompatibilityEvidence;
use App\Catalog\CatalogSourceHealth;
use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchPage;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Data\PluginSourceResult;
use App\Catalog\Exceptions\PluginReleaseNotFound;
use App\Catalog\Exceptions\PluginSourceException;
use App\Catalog\Exceptions\PluginSourceHttpError;
use App\Catalog\Exceptions\PluginSourceInvalidResponse;
use App\Catalog\PluginSource;
use App\Catalog\Transport\CatalogHttpClient;
use App\Models\CatalogCacheEntry;

/**
 * Implements the degradation-isolation and caching machinery ONCE,
 * shared by every concrete App\Catalog\Sources adapter, so it is
 * enforced identically rather than re-implemented three times:
 *
 * - `search()` is `final` and NEVER THROWS — see App\Catalog\PluginSource's
 *   docblock. It catches exactly App\Catalog\Exceptions\PluginSourceException
 *   (never a bare \Throwable — an unexpected bug must still surface
 *   loudly rather than silently becoming "just another degraded
 *   source," the same reasoning App\Plugins\PluginInventoryService's
 *   multi-catch already established in Task 13).
 * - A fresh cache hit (< 15 minutes old, config('catalog.cache.
 *   page_fresh_minutes')) short-circuits before any HTTP call at all.
 * - A live fetch that fails after retries falls back to the last
 *   successful cached result IF it is still within the 7-day retention
 *   window (config('catalog.cache.retention_days')), labeled both
 *   degraded AND stale; otherwise it returns an empty, labeled-degraded
 *   page. Either way the OTHER sources App\Catalog\UnifiedCatalogService
 *   also queried are entirely unaffected — this class only ever
 *   constructs a page for `$this->key()`.
 *
 * Concrete subclasses implement fetchAndNormalize() to do the actual
 * HTTP call(s) (via the shared App\Catalog\Transport\CatalogHttpClient)
 * and map the source's own response shape onto App\Catalog\Data\PluginRelease.
 */
abstract class AbstractPluginSource implements PluginSource
{
    public function __construct(
        protected readonly CatalogHttpClient $http,
        protected readonly CatalogCache $cache,
        protected readonly CatalogSourceHealth $health,
    ) {}

    final public function search(PluginSearchQuery $query): PluginSearchPage
    {
        $cacheKey = $this->cacheKey($query);
        $cached = $this->cache->find($cacheKey);

        if ($cached !== null && $this->cache->isFresh($cached)) {
            $items = $this->filterItems($this->itemsFromPayload($cached->payload), $query);

            return $this->finish($items, $query, degraded: false, message: null, servedFromCache: true, stale: false);
        }

        try {
            $items = $this->filterItems($this->fetchAndNormalize($query, $cacheKey, $cached), $query);
            $this->health->recordSuccess($this->key());

            return $this->finish($items, $query, degraded: false, message: null, servedFromCache: false, stale: false);
        } catch (PluginSourceException $exception) {
            $this->health->recordFailure($this->key(), $exception->getMessage());

            if ($cached !== null && $this->cache->isWithinRetention($cached)) {
                $items = $this->filterItems($this->itemsFromPayload($cached->payload), $query);

                return $this->finish($items, $query, degraded: true, message: $exception->getMessage(), servedFromCache: true, stale: true);
            }

            return $this->finish([], $query, degraded: true, message: $exception->getMessage(), servedFromCache: false, stale: false);
        }
    }

    /**
     * A source-specific narrowing/pagination hook applied to items
     * regardless of whether they came from cache or a live fetch — the
     * default is identity (a source, like Hangar/Modrinth, whose cache
     * key already encodes the query and whose HTTP request already
     * applied server-side filtering, needs no further narrowing).
     * App\Catalog\Sources\CraftKeeperCatalogSource overrides this: its
     * cache key is query-INDEPENDENT (the whole document is cached
     * once), so text/version/platform filtering and pagination happen
     * here instead.
     *
     * @param  list<PluginRelease>  $items
     * @return list<PluginRelease>
     */
    protected function filterItems(array $items, PluginSearchQuery $query): array
    {
        return $items;
    }

    /**
     * Attaches catalog-sourced compatibility evidence (see
     * App\Catalog\CatalogCompatibilityEvidence) based on the CURRENT
     * query — deliberately never done at normalization/cache-write
     * time, since a cached item can be served against a later query
     * with a different (or no) requested Minecraft version.
     *
     * @param  list<PluginRelease>  $items
     * @return list<PluginRelease>
     */
    private function attachEvidence(array $items, PluginSearchQuery $query): array
    {
        $tag = 'catalog.'.strtolower($this->key()->value).'.declared-minecraft-versions';

        return array_map(
            fn (PluginRelease $release) => $release->withCompatibilityEvidence(
                CatalogCompatibilityEvidence::forDeclaredVersions($tag, $release->minecraftVersions, $query->minecraftVersion),
            ),
            $items,
        );
    }

    /**
     * @param  list<PluginRelease>  $items
     */
    private function finish(array $items, PluginSearchQuery $query, bool $degraded, ?string $message, bool $servedFromCache, bool $stale): PluginSearchPage
    {
        return $this->page($this->attachEvidence($items, $query), $degraded, $message, $servedFromCache, $stale, $query);
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<PluginRelease>
     */
    protected function itemsFromPayload(array $payload): array
    {
        return array_values(array_map(fn (array $row) => PluginRelease::fromArray($row), $payload['items'] ?? []));
    }

    /**
     * @param  list<PluginRelease>  $items
     * @return array{items: list<array<string, mixed>>}
     */
    protected function payloadFromItems(array $items): array
    {
        return ['items' => array_map(fn (PluginRelease $r) => $r->toArray(), $items)];
    }

    /**
     * @param  list<PluginRelease>  $items
     */
    private function page(array $items, bool $degraded, ?string $message, bool $servedFromCache, bool $stale, PluginSearchQuery $query): PluginSearchPage
    {
        $result = $degraded
            ? PluginSourceResult::degraded($this->key(), $message ?? 'Unknown error', $servedFromCache, $stale)
            : PluginSourceResult::ok($this->key(), $servedFromCache, $stale);

        return new PluginSearchPage($items, [$result], $query->page, $query->perPage);
    }

    /**
     * A shared, unauthenticated GET-and-decode-JSON helper for the two
     * live single-release lookups (App\Catalog\Sources\HangarSource and
     * App\Catalog\Sources\ModrinthSource's release() implementations) —
     * NOT used by search(), which never throws. A 404 is translated to
     * the more specific PluginReleaseNotFound; any other
     * PluginSourceException (timeout, other HTTP error, oversized
     * response) propagates as-is, and a non-JSON-object/array body
     * raises PluginSourceInvalidResponse.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws PluginSourceException
     */
    protected function fetchJsonOrFail(string $url, array $query, PluginReleaseId $id): array
    {
        try {
            $response = $this->http->get($url, $query, null, null);
        } catch (PluginSourceHttpError $exception) {
            if ($exception->status === 404) {
                throw PluginReleaseNotFound::forId($id);
            }

            throw $exception;
        }

        $decoded = json_decode($response->body, true);

        if (! is_array($decoded)) {
            throw PluginSourceInvalidResponse::forUrl($url, 'expected a JSON body');
        }

        return $decoded;
    }

    abstract protected function cacheKey(PluginSearchQuery $query): string;

    /**
     * Performs the live fetch — sending If-None-Match/If-Modified-Since
     * from $cached when present — normalizes the response into
     * `list<PluginRelease>`, and persists it via
     * `$this->cache->put($cacheKey, $this->key(), <kind>, $this->payloadFromItems($items), $etag, $lastModified)`
     * before returning it. On a 304, implementations should instead call
     * `$this->cache->touchFreshness($cached)` and return
     * `$this->itemsFromPayload($cached->payload)` — no re-normalization
     * needed.
     *
     * @return list<PluginRelease>
     *
     * @throws PluginSourceException
     */
    abstract protected function fetchAndNormalize(PluginSearchQuery $query, string $cacheKey, ?CatalogCacheEntry $cached): array;
}
