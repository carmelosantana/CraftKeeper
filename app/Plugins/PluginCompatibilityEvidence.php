<?php

namespace App\Plugins;

/**
 * One piece of evidence PluginCompatibilityService weighed while
 * producing a PluginCompatibilityAssessment. `$supportsCompatibility` is
 * deliberately a tri-state: `true` when this evidence positively
 * supports compatibility, `false` when it positively contradicts it, and
 * `null` when it is informational only (e.g. "no api-version declared")
 * — present for transparency but never itself moving the verdict away
 * from Unknown. `$source` is a short, stable machine-readable tag (e.g.
 * "inventory.dependency", "jar-metadata.api-version",
 * "jar-metadata.platform") so a future evidence source (Task 14's
 * catalog release metadata) can be added without renaming existing ones.
 */
final readonly class PluginCompatibilityEvidence
{
    public function __construct(
        public string $source,
        public string $summary,
        public ?bool $supportsCompatibility,
    ) {}
}
