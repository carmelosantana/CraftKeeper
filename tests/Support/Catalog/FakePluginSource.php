<?php

namespace Tests\Support\Catalog;

use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchPage;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\PluginSource;
use App\Plugins\PluginProvenance;
use RuntimeException;

/**
 * A canned App\Catalog\PluginSource test double for
 * UnifiedCatalogServiceTest's merge/dedup/sort assertions — lets those
 * tests control exactly which PluginSearchPage each source returns
 * without going through Http::fake()/the real HTTP-backed adapters at
 * all (those are exercised separately, against the real adapters, in
 * HangarSourceTest/ModrinthSourceTest/CraftKeeperCatalogSourceTest and
 * the end-to-end test in UnifiedCatalogServiceTest).
 */
final class FakePluginSource implements PluginSource
{
    public function __construct(
        private readonly PluginProvenance $source,
        private readonly PluginSearchPage $page,
    ) {}

    public function key(): PluginProvenance
    {
        return $this->source;
    }

    public function search(PluginSearchQuery $query): PluginSearchPage
    {
        return $this->page;
    }

    public function release(PluginReleaseId $id): PluginRelease
    {
        throw new RuntimeException('Tests\Support\Catalog\FakePluginSource does not implement release().');
    }
}
