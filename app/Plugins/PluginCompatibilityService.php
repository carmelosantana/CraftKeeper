<?php

namespace App\Plugins;

/**
 * Turns an InspectedPlugin (plus the inventory's dependency graph, and
 * whatever server api-version the caller happens to know) into a
 * PluginCompatibilityAssessment — a verdict backed by evidence, never a
 * bare guess.
 *
 * THE CENTRAL RULE THIS CLASS ENFORCES: `Unknown` is the honest default
 * and stays that way absent positive evidence. Finding and successfully
 * parsing paper-plugin.yml/plugin.yml is necessary just to identify a
 * plugin at all, but it is NEVER, by itself, treated as evidence of
 * compatibility — a JAR "having valid metadata" or "loading" proves
 * nothing about whether it actually works on this server. `$compatible`
 * below is only ever set to true by a genuinely positive signal: a
 * declared hard dependency that IS present in the inventory, or a
 * declared api-version that IS satisfied by a known running server
 * version. Merely finding metadata, or a plugin declaring zero
 * dependencies, sets neither flag and therefore yields Unknown.
 *
 * Evidence sources wired in for real right now (per Task 13's ambiguity
 * resolution #3):
 *   - source-platform declarations: a foreign-platform descriptor
 *     (velocity-plugin.json/bungee.yml) with no Paper/Bukkit metadata is
 *     treated as genuine NEGATIVE evidence (this JAR is for a different
 *     platform entirely); the mere presence of paper-plugin.yml/
 *     plugin.yml is recorded as informational evidence only, per the
 *     rule above.
 *   - dependency satisfaction: via PluginDependencyGraph, built from
 *     whatever else PluginInventoryService found on disk in the same
 *     pass.
 *   - api-version comparison: only when the caller supplies a known
 *     $serverApiVersion — nothing in this task auto-detects the running
 *     server's own api-version (that's Task 11/15 territory), so this
 *     evidence source is accepted but not automatically populated here.
 *
 * Catalog release metadata (Task 14) is a future evidence source this
 * class is intentionally shaped to accept without a redesign: add
 * another optional parameter/evidence branch here when that data exists,
 * the same way $serverApiVersion already works.
 */
final class PluginCompatibilityService
{
    public function evaluate(InspectedPlugin $plugin, PluginDependencyGraph $graph, ?string $serverApiVersion = null): PluginCompatibilityAssessment
    {
        $evidence = [];
        $incompatible = false;
        $compatible = false;
        $warning = false;

        foreach ($plugin->diagnostics as $diagnostic) {
            if ($diagnostic->issue === PluginInspectionIssue::ForeignPlatform) {
                $incompatible = true;
                $evidence[] = new PluginCompatibilityEvidence('jar-metadata.platform', $diagnostic->message, false);

                continue;
            }

            // Every other inspection diagnostic (no metadata at all,
            // malformed YAML, an oversized/unreadable archive, ...)
            // means there simply isn't enough reliable metadata to say
            // anything positive OR negative — recorded for transparency,
            // but deliberately not moved into $warning/$compatible: "we
            // don't know" is Unknown, not a graded-down Warning.
            $evidence[] = new PluginCompatibilityEvidence('jar-metadata', $diagnostic->message, null);
        }

        if ($plugin->metadataSource !== null) {
            $evidence[] = new PluginCompatibilityEvidence(
                'jar-metadata.platform',
                "Declares itself loadable via {$plugin->metadataSource}.",
                null,
            );
        }

        if ($plugin->name !== null) {
            $missingHard = $graph->missingHardDependenciesFor($plugin->name);

            if ($missingHard !== []) {
                $incompatible = true;
                $evidence[] = new PluginCompatibilityEvidence(
                    'inventory.dependency',
                    'Missing required dependenc'.(count($missingHard) === 1 ? 'y' : 'ies').': '.implode(', ', $missingHard),
                    false,
                );
            } elseif ($plugin->hardDependencies !== []) {
                $compatible = true;
                $evidence[] = new PluginCompatibilityEvidence('inventory.dependency', 'All declared hard dependencies are present in the inventory.', true);
            }

            $missingSoft = $graph->missingSoftDependenciesFor($plugin->name);

            if ($missingSoft !== []) {
                $warning = true;
                $evidence[] = new PluginCompatibilityEvidence(
                    'inventory.dependency',
                    'Optional dependenc'.(count($missingSoft) === 1 ? 'y' : 'ies').' not present: '.implode(', ', $missingSoft),
                    null,
                );
            }
        }

        if ($plugin->apiVersion !== null && $serverApiVersion !== null) {
            if (version_compare($plugin->apiVersion, $serverApiVersion, '>')) {
                $incompatible = true;
                $evidence[] = new PluginCompatibilityEvidence(
                    'jar-metadata.api-version',
                    "Plugin declares api-version {$plugin->apiVersion}, newer than the server's {$serverApiVersion}.",
                    false,
                );
            } else {
                $compatible = true;
                $evidence[] = new PluginCompatibilityEvidence(
                    'jar-metadata.api-version',
                    "Plugin's declared api-version {$plugin->apiVersion} is supported by the server's {$serverApiVersion}.",
                    true,
                );
            }
        } elseif ($plugin->apiVersion !== null) {
            $evidence[] = new PluginCompatibilityEvidence(
                'jar-metadata.api-version',
                "Declares api-version {$plugin->apiVersion}; the running server's api-version is unknown, so this cannot be compared.",
                null,
            );
        }

        $state = match (true) {
            $incompatible => PluginCompatibilityState::Incompatible,
            $warning => PluginCompatibilityState::Warning,
            $compatible => PluginCompatibilityState::Compatible,
            default => PluginCompatibilityState::Unknown,
        };

        return new PluginCompatibilityAssessment($state, $evidence);
    }
}
