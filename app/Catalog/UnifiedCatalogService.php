<?php

namespace App\Catalog;

use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginSearchPage;
use App\Catalog\Data\PluginSearchQuery;

/**
 * The Stable Interface unified search: asks EVERY registered
 * App\Catalog\PluginSource, then merges without erasing provenance.
 *
 * "Merge without erasing provenance" (brief step 3) means two things,
 * both enforced here:
 *
 * 1. DEDUPLICATION is by EXACT (source, projectId) identity
 *    (App\Catalog\Data\PluginReleaseId::identityKey()) — never by
 *    display name. Two DIFFERENT real-world projects, even on the same
 *    source, can legitimately share a name (two different Hangar
 *    authors both naming a project "Essentials"); collapsing on name
 *    would silently discard one of them. Conversely, the SAME
 *    real-world plugin published on both Hangar and Modrinth is NEVER
 *    collapsed into one row — mergeByIdentity() only removes an EXACT
 *    repeat of (source, projectId) (which can legitimately happen if a
 *    live fetch and a stale-cache fallback both contributed the same
 *    project, or a source's own listing repeats a project), never a
 *    cross-source match. Every surviving PluginRelease therefore always
 *    still carries its own $sourceUrl/$id->source badge.
 *
 * 2. SORT is deterministic and NEVER an opaque popularity score
 *    (download counts, stars, etc. are never read for ranking
 *    purposes). Four explicit, explainable tiers, each a tiebreaker for
 *    the one before it:
 *      a. compatibilityRank() — derived from the SAME
 *         App\Plugins\PluginCompatibilityEvidence values
 *         App\Catalog\Sources\AbstractPluginSource already attached
 *         per-release (0 = a source positively confirms compatibility
 *         with the query's requested Minecraft version, 1 = no signal
 *         either way, 2 = a declared mismatch).
 *      b. installed relevance (App\Catalog\InstalledPluginIndex) — a
 *         release matching something already on this server (Task 13's
 *         inventory) sorts earlier.
 *      c. source trust — a fixed, documented, non-dynamic ranking
 *         (CraftKeeper Catalog is signed/curated and checksum-carrying
 *         at search time; Hangar is PaperMC's own first-party registry;
 *         Modrinth is the broadest, most general-purpose of the three).
 *      d. source ranking tiebreak — name, then project id — guarantees
 *         a fully deterministic order with no ties ever left to
 *         PHP array/hash-iteration order.
 *
 * Every App\Catalog\PluginSource::search() call is guaranteed not to
 * throw (see that interface's docblock), so no try/catch is needed
 * here at all — per-source degradation isolation is structural, not
 * defensive: one source's PluginSearchPage simply carries a degraded
 * App\Catalog\Data\PluginSourceResult while the loop moves on to every
 * other source unaffected.
 */
final class UnifiedCatalogService
{
    private const SOURCE_TRUST = [
        'Catalog' => 0,
        'Hangar' => 1,
        'Modrinth' => 2,
    ];

    /**
     * @param  iterable<PluginSource>  $sources
     */
    public function __construct(
        private readonly iterable $sources,
    ) {}

    public function search(PluginSearchQuery $query): PluginSearchPage
    {
        $pages = [];

        foreach ($this->sources as $source) {
            $pages[] = $source->search($query);
        }

        $items = $this->sortDeterministically($this->mergeByIdentity($pages), $query);

        $sourceResults = [];

        foreach ($pages as $page) {
            array_push($sourceResults, ...$page->sourceResults);
        }

        return new PluginSearchPage($items, $sourceResults, $query->page, $query->perPage);
    }

    /**
     * @param  list<PluginSearchPage>  $pages
     * @return list<PluginRelease>
     */
    private function mergeByIdentity(array $pages): array
    {
        $seen = [];
        $items = [];

        foreach ($pages as $page) {
            foreach ($page->items as $item) {
                $identityKey = $item->id->identityKey();

                if (isset($seen[$identityKey])) {
                    continue;
                }

                $seen[$identityKey] = true;
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  list<PluginRelease>  $items
     * @return list<PluginRelease>
     */
    private function sortDeterministically(array $items, PluginSearchQuery $query): array
    {
        $installed = new InstalledPluginIndex;

        $decorated = array_map(fn (PluginRelease $item) => [
            'item' => $item,
            'key' => $this->sortKey($item, $installed),
        ], $items);

        usort($decorated, function (array $a, array $b): int {
            foreach ($a['key'] as $index => $value) {
                $comparison = $value <=> $b['key'][$index];

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return array_map(fn (array $row) => $row['item'], $decorated);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: string, 4: string}
     */
    private function sortKey(PluginRelease $item, InstalledPluginIndex $installed): array
    {
        return [
            $this->compatibilityRank($item),
            $installed->isInstalled($item->name) ? 0 : 1,
            self::SOURCE_TRUST[$item->source()->value] ?? 99,
            strtolower($item->name),
            $item->id->projectId,
        ];
    }

    /**
     * 0 = at least one attached evidence entry positively confirms
     * compatibility; 1 = informational only / nothing to compare; 2 = a
     * declared mismatch and nothing positive. See this class's
     * docblock — this reads App\Plugins\PluginCompatibilityEvidence
     * values every source already attached; it does not compute a
     * second, independent signal.
     */
    private function compatibilityRank(PluginRelease $item): int
    {
        $supports = null;

        foreach ($item->compatibilityEvidence as $evidence) {
            if ($evidence->supportsCompatibility === true) {
                $supports = true;
                break;
            }

            if ($evidence->supportsCompatibility === false) {
                $supports = false;
            }
        }

        return match ($supports) {
            true => 0,
            null => 1,
            false => 2,
        };
    }
}
