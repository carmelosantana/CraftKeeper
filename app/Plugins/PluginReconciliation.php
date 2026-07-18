<?php

namespace App\Plugins;

use App\Models\PluginInstallation;

/**
 * The report PluginInventoryService::reconcile() returns alongside
 * whatever it persisted: every PluginInstallation it created (an
 * addition), marked missing (a removal — never deleted, see
 * PluginInstallation's `missing_since` column), or updated because the
 * on-disk checksum changed, plus every plugin name shared by more than
 * one on-disk file (never silently merged — both installations are
 * still created/updated normally; this is purely the "flag it" report)
 * and every logical plugin path where BOTH the enabled and ".disabled"
 * form exist on disk at once (an unresolved conflict PluginInventoryService
 * deliberately leaves untouched rather than guessing which is
 * authoritative).
 */
final readonly class PluginReconciliation
{
    /**
     * @param  list<PluginInstallation>  $additions
     * @param  list<PluginInstallation>  $removals
     * @param  list<PluginInstallation>  $changes
     * @param  list<PluginInstallation>  $unchanged
     * @param  array<string, list<string>>  $duplicateNames  plugin name => logical relative paths sharing it
     * @param  array<string, list<string>>  $pathConflicts  logical relative path => on-disk filenames colliding on it
     */
    public function __construct(
        public array $additions,
        public array $removals,
        public array $changes,
        public array $unchanged,
        public array $duplicateNames,
        public array $pathConflicts,
    ) {}
}
