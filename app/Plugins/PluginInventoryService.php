<?php

namespace App\Plugins;

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
                : $existing->provenance;

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
            } catch (UnsafeMinecraftPath) {
                // Defensive only, mirroring App\Config\ConfigDiscoveryService's
                // identical guard: the walk above already proved this
                // entry resolves inside the root, so this should never
                // trigger in practice. It's still caught rather than left
                // to propagate, because the ONE thing worse than skipping
                // one oddly-named file (e.g. a literal "con.jar" — a
                // reserved device name MinecraftPath always refuses) is
                // letting it crash reconciliation for every OTHER plugin
                // in the same scan.
                continue;
            }

            $discovered[] = new DiscoveredPlugin(
                logicalRelativePath: 'plugins/'.$logical,
                onDiskRelativePath: 'plugins/'.$onDiskName,
                enabled: $enabled,
                inspection: $this->inspector->inspect($path),
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
