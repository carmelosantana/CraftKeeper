<?php

namespace App\Plugins;

use App\Filesystem\MinecraftPath;

/**
 * The result of JarInspector::inspect() — always returned, never thrown,
 * regardless of how hostile or malformed the archive at $path turns out
 * to be. `$sha256`/`$sizeBytes`/`$modifiedAt` are computed straight from
 * the file on disk and are therefore always populated; every other field
 * depends on successfully locating and parsing paper-plugin.yml or
 * plugin.yml inside the archive and is null (or empty, for the
 * dependency lists) whenever that didn't fully succeed — see
 * `$diagnostics` for why.
 */
final readonly class InspectedPlugin
{
    /**
     * @param  list<string>  $hardDependencies
     * @param  list<string>  $softDependencies
     * @param  list<PluginInspectionDiagnostic>  $diagnostics
     */
    public function __construct(
        public MinecraftPath $path,
        public ?string $name,
        public ?string $version,
        public ?string $mainClass,
        public ?string $apiVersion,
        public array $hardDependencies,
        public array $softDependencies,
        public ?string $metadataSource,
        public string $sha256,
        public int $sizeBytes,
        public int $modifiedAt,
        public array $diagnostics,
    ) {}

    public function hasMetadata(): bool
    {
        return $this->metadataSource !== null;
    }

    public function hasDiagnostics(): bool
    {
        return $this->diagnostics !== [];
    }
}
