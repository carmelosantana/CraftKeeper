<?php

namespace App\Plugins;

use App\Filesystem\Exceptions\MinecraftRootUnavailable;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftPath;
use App\Models\PluginArtifact;
use App\Models\PluginInstallation;
use Illuminate\Support\Carbon;

/**
 * Scans `plugins/` for `*.jar`/`*.jar.disabled`, inspects each one via
 * JarInspector (never extracting or executing anything — see that
 * class), and reconciles the result against the `plugin_installations`
 * table: additions (a new file), removals (a tracked row whose file is
 * now gone — see PluginInstallation's `missing_since` column, never
 * deleted outright), changes (checksum differs from what was recorded),
 * duplicate names (two files whose metadata declares the same plugin
 * name — flagged, never silently merged into one row), disabled
 * (`.jar.disabled`), and same-logical-path conflicts (both the enabled
 * and disabled form present on disk at once — left untouched rather than
 * guessing which is authoritative).
 *
 * Deterministic: given the same disk state and the same
 * `plugin_installations`/`plugin_artifacts` rows, reconcile() always
 * produces the same additions/removals/changes/unchanged/duplicateNames/
 * pathConflicts report and leaves the database in the same end state,
 * regardless of how many times it runs.
 *
 * `plugins/` is scanned NON-recursively — real Paper/Bukkit JARs sit
 * directly in `plugins/`; a subdirectory there (e.g. `plugins/Vault/`)
 * is that plugin's own config directory (already covered by Task 6's
 * ConfigDiscoveryService), never a place another JAR legitimately lives.
 *
 * Update-staging and rollback artifacts are Task 15's job (the actual
 * lifecycle operations that create them); this task only defines the
 * disk-vs-database reconciliation those future states will report
 * through, and deliberately does not invent a staging/rollback directory
 * convention no other code yet produces.
 */
final class PluginInventoryService
{
    private const DISABLED_SUFFIX = '.disabled';

    public function __construct(private readonly JarInspector $inspector) {}

    /**
     * A read-only snapshot of every currently-discoverable plugin's
     * JarInspector reading, WITHOUT writing anything to
     * plugin_installations — Task 15's App\Plugins\PluginLifecycleService
     * uses this to build a fresh App\Plugins\PluginDependencyGraph (via
     * PluginDependencyGraph::build()) when assessing a candidate
     * install/update's compatibility against what is ACTUALLY on disk
     * right now, without needing reconcile()'s side effects (and without
     * duplicating scan()'s own discovery logic here).
     *
     * @return list<InspectedPlugin>
     */
    public function currentInspections(): array
    {
        [$discovered] = $this->scan();

        return array_map(
            fn (DiscoveredPlugin $entry): InspectedPlugin => $entry->inspection,
            $discovered,
        );
    }

    public function reconcile(): PluginReconciliation
    {
        [$discovered, $pathConflicts] = $this->scan();

        $graph = PluginDependencyGraph::build(array_map(
            fn (DiscoveredPlugin $entry): InspectedPlugin => $entry->inspection,
            $discovered,
        ));
        $compatibility = new PluginCompatibilityService;
        $duplicateNames = $this->duplicateNames($discovered);

        $seenLogicalPaths = [];
        $additions = [];
        $changes = [];
        $unchanged = [];

        foreach ($discovered as $entry) {
            $seenLogicalPaths[] = $entry->logicalRelativePath;

            $existing = PluginInstallation::query()->where('relative_path', $entry->logicalRelativePath)->first();
            $assessment = $compatibility->evaluate($entry->inspection, $graph);

            $attributes = [
                'name' => $entry->inspection->name,
                'version' => $entry->inspection->version,
                'main_class' => $entry->inspection->mainClass,
                'api_version' => $entry->inspection->apiVersion,
                'hard_dependencies' => $entry->inspection->hardDependencies,
                'soft_dependencies' => $entry->inspection->softDependencies,
                'metadata_source' => $entry->inspection->metadataSource,
                'sha256' => $entry->inspection->sha256,
                'size_bytes' => $entry->inspection->sizeBytes,
                'file_modified_at' => $entry->inspection->modifiedAt > 0 ? Carbon::createFromTimestamp($entry->inspection->modifiedAt) : null,
                'enabled' => $entry->enabled,
                'duplicate_name' => $entry->inspection->name !== null && array_key_exists($entry->inspection->name, $duplicateNames),
                'inspection_diagnostics' => array_map(
                    fn (PluginInspectionDiagnostic $diagnostic): array => ['issue' => $diagnostic->issue->value, 'message' => $diagnostic->message],
                    $entry->inspection->diagnostics,
                ),
                'compatibility_state' => $assessment->state->value,
                'compatibility_evidence' => array_map(
                    fn (PluginCompatibilityEvidence $evidence): array => [
                        'source' => $evidence->source,
                        'summary' => $evidence->summary,
                        'supports_compatibility' => $evidence->supportsCompatibility,
                    ],
                    $assessment->evidence,
                ),
                'last_seen_at' => now(),
                'missing_since' => null,
            ];

            if ($existing === null) {
                $installation = PluginInstallation::query()->create([
                    ...$attributes,
                    'relative_path' => $entry->logicalRelativePath,
                    'provenance' => $this->resolveProvenanceForNew($entry->inspection->sha256),
                ]);
                $additions[] = $installation;

                continue;
            }

            $sha256Changed = $existing->sha256 !== $entry->inspection->sha256;
            $provenance = $sha256Changed
                ? $this->resolveProvenanceForChange($existing->provenance, $entry->inspection->sha256)
                : $this->adoptKnownProvenance($existing->provenance, $entry->inspection->sha256);

            $existing->forceFill([...$attributes, 'provenance' => $provenance])->save();

            if ($sha256Changed) {
                $changes[] = $existing;
            } else {
                $unchanged[] = $existing;
            }
        }

        $removals = $this->markMissing($seenLogicalPaths, $pathConflicts);

        return new PluginReconciliation($additions, $removals, $changes, $unchanged, $duplicateNames, $pathConflicts);
    }

    /**
     * @return array{0: list<DiscoveredPlugin>, 1: array<string, list<string>>}
     */
    private function scan(): array
    {
        $pluginsDir = $this->canonicalPluginsDir();

        if ($pluginsDir === null) {
            return [[], []];
        }

        $entries = @scandir($pluginsDir);

        if ($entries === false) {
            return [[], []];
        }

        sort($entries);

        /** @var array<string, list<string>> $byLogicalPath */
        $byLogicalPath = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $absoluteEntry = $pluginsDir.'/'.$entry;
            $resolved = realpath($absoluteEntry);

            if ($resolved === false || filetype($resolved) !== 'file') {
                continue;
            }

            $logical = $this->logicalNameFor($entry);

            if ($logical === null) {
                continue;
            }

            $byLogicalPath[$logical][] = $entry;
        }

        $discovered = [];
        $pathConflicts = [];

        foreach ($byLogicalPath as $logical => $onDiskNames) {
            if (count($onDiskNames) > 1) {
                $pathConflicts['plugins/'.$logical] = $onDiskNames;

                continue;
            }

            $onDiskName = $onDiskNames[0];
            $enabled = $onDiskName === $logical;

            try {
                $path = MinecraftPath::fromUserInput('plugins/'.$onDiskName);
                $inspection = $this->inspector->inspect($path);
            } catch (UnsafeMinecraftPath|NotARegularFile|MinecraftRootUnavailable) {
                // Defensive isolation: the walk above already proved this
                // entry should resolve inside the root. UnsafeMinecraftPath
                // never triggers in practice (see below). But NotARegularFile
                // and MinecraftRootUnavailable can: a race condition between
                // the filetype check and path resolution, or a directory/FIFO/
                // socket somehow lingering in the plugins/ tree, must not crash
                // reconciliation for every OTHER plugin in the same scan. This
                // exception guard ensures exactly one bad entry is skipped
                // (e.g. a literal "con.jar" reserved device name, or a directory
                // named "weird.jar") rather than aborting the whole inventory.
                continue;
            }

            $discovered[] = new DiscoveredPlugin(
                logicalRelativePath: 'plugins/'.$logical,
                onDiskRelativePath: 'plugins/'.$onDiskName,
                enabled: $enabled,
                inspection: $inspection,
            );
        }

        return [$discovered, $pathConflicts];
    }

    private function logicalNameFor(string $filename): ?string
    {
        if (str_ends_with($filename, '.jar'.self::DISABLED_SUFFIX)) {
            return substr($filename, 0, -strlen(self::DISABLED_SUFFIX));
        }

        if (str_ends_with($filename, '.jar')) {
            return $filename;
        }

        return null;
    }

    /**
     * @param  list<DiscoveredPlugin>  $discovered
     * @return array<string, list<string>>
     */
    private function duplicateNames(array $discovered): array
    {
        /** @var array<string, list<string>> $byName */
        $byName = [];

        foreach ($discovered as $entry) {
            if ($entry->inspection->name === null) {
                continue;
            }

            $byName[$entry->inspection->name][] = $entry->logicalRelativePath;
        }

        return array_filter($byName, fn (array $paths): bool => count($paths) > 1);
    }

    /**
     * Every PluginInstallation not seen on THIS scan, and not itself
     * sitting at a logical path this scan deliberately left alone as an
     * unresolved on-disk conflict, is a genuine removal: its file is
     * gone. Marked via `missing_since`, never deleted — see this class's
     * docblock.
     *
     * @param  list<string>  $seenLogicalPaths
     * @param  array<string, list<string>>  $pathConflicts
     * @return list<PluginInstallation>
     */
    private function markMissing(array $seenLogicalPaths, array $pathConflicts): array
    {
        $conflictPaths = array_keys($pathConflicts);

        $query = PluginInstallation::query()->whereNull('missing_since');

        // relative_path can never legitimately be empty; using it as the
        // "nothing was seen" placeholder avoids relying on driver-
        // specific whereNotIn([]) behavior.
        $query->whereNotIn('relative_path', $seenLogicalPaths === [] ? [''] : $seenLogicalPaths);

        if ($conflictPaths !== []) {
            $query->whereNotIn('relative_path', $conflictPaths);
        }

        $removals = [];

        foreach ($query->get() as $installation) {
            $installation->forceFill(['missing_since' => now()])->save();
            $removals[] = $installation;
        }

        return $removals;
    }

    private function resolveProvenanceForNew(string $sha256): string
    {
        $artifact = PluginArtifact::query()->where('sha256', $sha256)->first();

        return $artifact instanceof PluginArtifact && $artifact->source !== null
            ? $artifact->source
            : PluginProvenance::Manual->value;
    }

    /**
     * Adopt a real source for an already-tracked file whose bytes have NOT
     * changed, when one is now known for exactly those bytes.
     *
     * Without this, provenance was only ever evaluated at two moments: when
     * a file was first seen, and when its checksum changed. An installation
     * already tracked as unattributed therefore kept that label forever,
     * even once `plugin_artifacts` gained a row naming its source — which is
     * precisely what happens on upgrade to 1.1.3, and on any re-install of
     * an identical version (same bytes in, checksum unchanged, so the
     * "changed" path never runs).
     *
     * Only ever an upgrade from Manual — the "we cannot attribute this"
     * value — to a source recorded for that exact checksum. It cannot
     * overwrite an already-known source and cannot invent one: the artifact
     * table is content-addressed, so a row matching this file's checksum
     * describes literally these bytes, not something merely similar.
     */
    private function adoptKnownProvenance(string $existingProvenance, string $sha256): string
    {
        if ($existingProvenance !== PluginProvenance::Manual->value) {
            return $existingProvenance;
        }

        $artifact = PluginArtifact::query()->where('sha256', $sha256)->first();

        return $artifact instanceof PluginArtifact && $artifact->source !== null
            ? $artifact->source
            : $existingProvenance;
    }

    private function resolveProvenanceForChange(string $existingProvenance, string $newSha256): string
    {
        $artifact = PluginArtifact::query()->where('sha256', $newSha256)->first();

        return $artifact instanceof PluginArtifact && $artifact->source !== null
            ? $artifact->source
            : $existingProvenance;
    }

    private function canonicalPluginsDir(): ?string
    {
        $configured = (string) config('craftkeeper.minecraft_root');
        $rootReal = $configured !== '' ? realpath($configured) : false;

        if ($rootReal === false) {
            return null;
        }

        $pluginsReal = realpath($rootReal.'/plugins');

        if ($pluginsReal === false || ! is_dir($pluginsReal)) {
            return null;
        }

        // realpath() dereferences every symlink component; a plugins/
        // that resolves outside the canonical root (e.g. swapped for an
        // escaping symlink) is never descended into.
        if ($pluginsReal !== $rootReal && ! str_starts_with($pluginsReal, $rootReal.'/')) {
            return null;
        }

        return $pluginsReal;
    }
}
