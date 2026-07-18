<?php

namespace App\Plugins;

/**
 * One named plugin's position in a PluginDependencyGraph: what it
 * declares it depends on, which of those declarations are actually
 * satisfied by another named plugin elsewhere in the same graph, and
 * which other plugins declare a dependency on it.
 */
final readonly class PluginDependencyNode
{
    /**
     * @param  list<string>  $hardDependencies
     * @param  list<string>  $softDependencies
     * @param  list<string>  $missingHardDependencies
     * @param  list<string>  $missingSoftDependencies
     * @param  list<string>  $dependents
     */
    public function __construct(
        public string $name,
        public array $hardDependencies,
        public array $softDependencies,
        public array $missingHardDependencies,
        public array $missingSoftDependencies,
        public array $dependents,
    ) {}
}
