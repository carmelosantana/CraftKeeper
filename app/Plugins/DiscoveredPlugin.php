<?php

namespace App\Plugins;

/**
 * One file PluginInventoryService found while scanning `plugins/` for
 * `*.jar`/`*.jar.disabled`, paired with its JarInspector reading.
 * `$logicalRelativePath` is always the ENABLED-form path (e.g.
 * "plugins/EssentialsX.jar") regardless of which form is actually on
 * disk — the stable identity PluginInstallation rows are keyed on, so a
 * plugin toggling between enabled/disabled (by any means, not just
 * through CraftKeeper) is recognized as the same installation rather
 * than a spurious removal-then-addition pair. `$onDiskRelativePath` is
 * whichever form was actually found (with ".disabled" when `$enabled` is
 * false).
 */
final readonly class DiscoveredPlugin
{
    public function __construct(
        public string $logicalRelativePath,
        public string $onDiskRelativePath,
        public bool $enabled,
        public InspectedPlugin $inspection,
    ) {}
}
