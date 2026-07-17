<?php

namespace App\Config;

use App\Filesystem\MinecraftPath;

/**
 * One entry in the discovered configuration inventory. `$provenance` uses
 * the plan's exact product-facing vocabulary ("Built in," "Plugin,"
 * "Discovered," ... — see docs/superpowers/plans "Provenance is always
 * visible"), and `$recognized` is independent of it: whether CraftKeeper
 * has (or, once Task 7 lands, will have) schema-guided editing for this
 * specific file, as opposed to only generic source editing.
 */
final readonly class DiscoveredFile
{
    public function __construct(
        public MinecraftPath $path,
        public DiscoveredFileCategory $category,
        public string $provenance,
        public bool $recognized,
        public string $format,
        public int $sizeBytes,
    ) {}
}
