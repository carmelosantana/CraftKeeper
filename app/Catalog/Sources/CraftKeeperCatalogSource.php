<?php

namespace App\Catalog\Sources;

use App\Catalog\CatalogCache;
use App\Catalog\CatalogSourceHealth;
use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Exceptions\PluginReleaseNotFound;
use App\Catalog\Exceptions\PluginSourceInvalidResponse;
use App\Catalog\Transport\CatalogHttpClient;
use App\Models\CatalogCacheEntry;
use App\Plugins\PluginProvenance;
use DateTimeImmutable;

/**
 * Reads the single, independent carmelosantana/minecraft-plugin-catalog
 * JSON document (resources/catalog/plugin-catalog.schema.json is its
 * contract — see docs/architecture/plugin-catalog.md) and normalizes it
 * into App\Catalog\Data\PluginRelease values tagged
 * App\Plugins\PluginProvenance::Catalog.
 *
 * Unlike Hangar/Modrinth (one server-side search call per distinct
 * query), this source fetches the WHOLE document with a single cache
 * key (self::CACHE_KEY, independent of the query) and applies text/
 * version/platform filtering plus pagination client-side in
 * filterItems() — which is exactly what gives it the brief's "retain
 * the last successful CraftKeeper Catalog for 7 days" property for
 * free: the one cache row IS the last successful catalog, not tied to
 * any one query.
 *
 * A release with `withdrawn: true` normalizes successfully (see
 * CraftKeeperReleaseNormalizer) but is excluded from search() results —
 * filterItems() also collapses each plugin down to its single latest
 * non-withdrawn release, matching the "one card per project" shape
 * Hangar/Modrinth's own search endpoints return. release() does NOT
 * apply that exclusion: a specific (slug, version) lookup can still
 * resolve a withdrawn release (e.g. to inspect an already-installed one
 * that was later pulled) — only an unqualified "latest" lookup
 * (`$id->version === null`) skips withdrawn candidates.
 */
final class CraftKeeperCatalogSource extends AbstractPluginSource
{
    private const CACHE_KEY = 'catalog:craftkeeper:document';

    public function __construct(
        CatalogHttpClient $http,
        CatalogCache $cache,
        CatalogSourceHealth $health,
        private readonly CraftKeeperReleaseNormalizer $normalizer,
    ) {
        parent::__construct($http, $cache, $health);
    }

    public function key(): PluginProvenance
    {
        return PluginProvenance::Catalog;
    }

    protected function cacheKey(PluginSearchQuery $query): string
    {
        return self::CACHE_KEY;
    }

    /**
     * @return list<PluginRelease>
     */
    protected function fetchAndNormalize(PluginSearchQuery $query, string $cacheKey, ?CatalogCacheEntry $cached): array
    {
        $url = (string) config('catalog.sources.craftkeeper.url');

        $response = $this->http->get($url, [], $cached?->etag, $cached?->last_modified);

        if ($response->notModified) {
            $this->cache->touchFreshness($cached);

            return $this->itemsFromPayload($cached->payload);
        }

        $document = json_decode($response->body, true);

        if (! is_array($document) || ! isset($document['plugins']) || ! is_array($document['plugins'])) {
            throw PluginSourceInvalidResponse::forUrl($url, 'expected a JSON object with a "plugins" array');
        }

        $items = $this->normalizeDocument($document);

        $this->cache->put($cacheKey, $this->key(), 'catalog-snapshot', $this->payloadFromItems($items), $response->etag, $response->lastModified);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<PluginRelease>
     */
    private function normalizeDocument(array $document): array
    {
        $items = [];

        foreach ($document['plugins'] as $plugin) {
            if (! is_array($plugin) || ! isset($plugin['releases']) || ! is_array($plugin['releases'])) {
                continue;
            }

            foreach ($plugin['releases'] as $release) {
                if (! is_array($release)) {
                    continue;
                }

                $normalized = $this->normalizer->normalize($plugin, $release);

                if ($normalized !== null) {
                    $items[] = $normalized;
                }
            }
        }

        return $items;
    }

    /**
     * @param  list<PluginRelease>  $items
     * @return list<PluginRelease>
     */
    protected function filterItems(array $items, PluginSearchQuery $query): array
    {
        /** @var array<string, PluginRelease> $latestPerPlugin */
        $latestPerPlugin = [];

        foreach ($items as $item) {
            if ($item->withdrawn) {
                continue;
            }

            $existing = $latestPerPlugin[$item->slug] ?? null;

            if ($existing === null || $this->isNewer($item, $existing)) {
                $latestPerPlugin[$item->slug] = $item;
            }
        }

        $matched = array_values(array_filter($latestPerPlugin, fn (PluginRelease $item) => $this->matchesQuery($item, $query)));

        $offset = max(0, ($query->page - 1) * $query->perPage);

        return array_slice($matched, $offset, $query->perPage);
    }

    private function isNewer(PluginRelease $candidate, PluginRelease $current): bool
    {
        if ($candidate->releasedAt === null) {
            return false;
        }

        if ($current->releasedAt === null) {
            return true;
        }

        return $candidate->releasedAt > $current->releasedAt;
    }

    private function matchesQuery(PluginRelease $item, PluginSearchQuery $query): bool
    {
        if ($query->query !== null && $query->query !== '') {
            $haystack = strtolower($item->name.' '.$item->slug.' '.$item->description);

            if (! str_contains($haystack, strtolower($query->query))) {
                return false;
            }
        }

        if ($query->platform !== null && ! in_array(strtolower($query->platform), array_map(strtolower(...), $item->platforms), true)) {
            return false;
        }

        if ($query->minecraftVersion !== null && ! in_array($query->minecraftVersion, $item->minecraftVersions, true)) {
            return false;
        }

        return true;
    }

    public function release(PluginReleaseId $id): PluginRelease
    {
        $cached = $this->cache->find(self::CACHE_KEY);
        $items = null;

        if ($cached !== null && $this->cache->isWithinRetention($cached)) {
            $items = $this->itemsFromPayload($cached->payload);
        }

        if ($items === null) {
            // No usable cache — release() is allowed to let a transport
            // failure propagate (see App\Catalog\PluginSource's
            // docblock); it is not covered by search()'s "never throws"
            // isolation guarantee.
            $items = $this->fetchAndNormalize(new PluginSearchQuery, self::CACHE_KEY, $cached);
            $this->health->recordSuccess($this->key());
        }

        foreach ($items as $item) {
            if ($item->slug === $id->projectId && $id->version !== null && $item->version === $id->version) {
                return $item;
            }
        }

        if ($id->version === null) {
            $candidates = array_values(array_filter(
                $items,
                fn (PluginRelease $item) => $item->slug === $id->projectId && ! $item->withdrawn,
            ));

            usort(
                $candidates,
                fn (PluginRelease $a, PluginRelease $b) => ($b->releasedAt ?? new DateTimeImmutable('@0')) <=> ($a->releasedAt ?? new DateTimeImmutable('@0')),
            );

            if ($candidates !== []) {
                return $candidates[0];
            }
        }

        throw PluginReleaseNotFound::forId($id);
    }
}
