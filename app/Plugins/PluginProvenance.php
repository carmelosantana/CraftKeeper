<?php

namespace App\Plugins;

/**
 * The subset of the plan's product-facing provenance vocabulary ("Built
 * in," "Plugin," "Discovered," "Catalog," "Hangar," "Modrinth," or
 * "Manual" — see docs/superpowers/plans "Provenance is always visible")
 * that applies to a PluginInstallation row. `Manual` is what
 * PluginInventoryService assigns any JAR it discovers on disk that it
 * cannot attribute to a known source release by exact checksum match — a
 * human placed or built that file some other way. `Catalog`/`Hangar`/
 * `Modrinth` are only ever assigned once a checksum EXACTLY matches a
 * PluginArtifact row recorded with that source (Task 14/15 populate
 * those rows; this task only reads them — see
 * PluginInventoryService::resolveProvenanceForChange()).
 */
enum PluginProvenance: string
{
    case Manual = 'Manual';
    case Catalog = 'Catalog';
    case Hangar = 'Hangar';
    case Modrinth = 'Modrinth';
}
