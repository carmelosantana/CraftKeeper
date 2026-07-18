<?php

namespace App\Catalog\Data;

/**
 * Returned by BOTH a single App\Catalog\PluginSource::search() call and
 * App\Catalog\UnifiedCatalogService::search() — the same shape at both
 * layers on purpose: the unified page is exactly "every source's items,
 * merged/deduped/sorted" plus "every source's PluginSourceResult,
 * concatenated." A single-source page always carries exactly one
 * $sourceResults entry; a unified page carries one per registered
 * source, so a caller can always see which source(s) contributed items
 * and which (if any) are degraded — see App\Catalog\Data\PluginSourceResult.
 */
final readonly class PluginSearchPage
{
    /**
     * @param  list<PluginRelease>  $items
     * @param  list<PluginSourceResult>  $sourceResults
     */
    public function __construct(
        public array $items,
        public array $sourceResults,
        public int $page = 1,
        public int $perPage = 20,
    ) {}
}
