<?php

namespace App\Catalog;

use App\Plugins\PluginCompatibilityEvidence;

/**
 * Builds the ONE piece of catalog-sourced compatibility evidence every
 * App\Catalog\Data\PluginRelease carries, reusing App\Plugins\
 * PluginCompatibilityEvidence's tri-state vocabulary from Task 13 rather
 * than inventing a second one (see PluginRelease's docblock). Applied
 * uniformly by App\Catalog\Sources\AbstractPluginSource::search() AFTER
 * items are resolved from either a live fetch or the cache — never
 * baked into a cached payload — specifically so a cached CraftKeeper
 * Catalog document (whose cache key does not vary per query) always
 * reflects the CURRENT query's requested Minecraft version rather than
 * whatever query happened to trigger the original cache write.
 *
 * This is deliberately a single declared-version comparison, not a
 * popularity or ranking score — see App\Catalog\UnifiedCatalogService's
 * docblock for why that distinction matters for sorting.
 */
final class CatalogCompatibilityEvidence
{
    /**
     * @param  list<string>  $minecraftVersions
     * @return list<PluginCompatibilityEvidence>
     */
    public static function forDeclaredVersions(string $sourceTag, array $minecraftVersions, ?string $queriedVersion): array
    {
        if ($minecraftVersions === []) {
            return [new PluginCompatibilityEvidence(
                source: $sourceTag,
                summary: 'No declared Minecraft version information is available for this release.',
                supportsCompatibility: null,
            )];
        }

        if ($queriedVersion === null) {
            return [new PluginCompatibilityEvidence(
                source: $sourceTag,
                summary: 'Declares support for: '.implode(', ', $minecraftVersions).'.',
                supportsCompatibility: null,
            )];
        }

        $matches = in_array($queriedVersion, $minecraftVersions, true);

        return [new PluginCompatibilityEvidence(
            source: $sourceTag,
            summary: $matches
                ? "Declares support for the requested Minecraft version {$queriedVersion}."
                : "Does not declare support for the requested Minecraft version {$queriedVersion} (declares: ".implode(', ', $minecraftVersions).').',
            supportsCompatibility: $matches,
        )];
    }
}
