<?php

namespace App\Plugins;

/**
 * The inventory's dependency graph: for every named, identifiable plugin
 * (an InspectedPlugin with a non-null `name`), which of its declared
 * hard/soft dependencies are actually present elsewhere in the same
 * inventory — by exact declared name, the only identity a plugin.yml's
 * `depend`/`softdepend` entry or a paper-plugin.yml's
 * `dependencies.server` key ever carries — and, in the other direction,
 * which OTHER plugins declare a dependency on it. An InspectedPlugin
 * whose name could not be determined (missing/malformed metadata) simply
 * has no node and is never anyone's dependency target.
 *
 * PluginCompatibilityService is this graph's primary consumer: "is this
 * plugin's hard dependency actually present in the inventory" is one of
 * its accepted evidence sources.
 */
final readonly class PluginDependencyGraph
{
    /**
     * @param  array<string, PluginDependencyNode>  $nodes  keyed by plugin name
     */
    public function __construct(public array $nodes) {}

    /**
     * @param  list<InspectedPlugin>  $plugins
     */
    public static function build(array $plugins): self
    {
        /** @var array<string, InspectedPlugin> $named */
        $named = [];

        foreach ($plugins as $plugin) {
            if ($plugin->name !== null) {
                $named[$plugin->name] = $plugin;
            }
        }

        /** @var array<string, list<string>> $dependents */
        $dependents = [];

        foreach ($named as $plugin) {
            foreach ([...$plugin->hardDependencies, ...$plugin->softDependencies] as $dependencyName) {
                $dependents[$dependencyName][] = $plugin->name;
            }
        }

        $nodes = [];

        foreach ($named as $name => $plugin) {
            $nodes[$name] = new PluginDependencyNode(
                name: $name,
                hardDependencies: $plugin->hardDependencies,
                softDependencies: $plugin->softDependencies,
                missingHardDependencies: array_values(array_filter(
                    $plugin->hardDependencies,
                    fn (string $dependencyName): bool => ! array_key_exists($dependencyName, $named),
                )),
                missingSoftDependencies: array_values(array_filter(
                    $plugin->softDependencies,
                    fn (string $dependencyName): bool => ! array_key_exists($dependencyName, $named),
                )),
                dependents: array_values(array_unique($dependents[$name] ?? [])),
            );
        }

        return new self($nodes);
    }

    /**
     * @return list<string>
     */
    public function missingHardDependenciesFor(string $name): array
    {
        return $this->nodes[$name]->missingHardDependencies ?? [];
    }

    /**
     * @return list<string>
     */
    public function missingSoftDependenciesFor(string $name): array
    {
        return $this->nodes[$name]->missingSoftDependencies ?? [];
    }

    /**
     * @return list<string>
     */
    public function dependentsOf(string $name): array
    {
        return $this->nodes[$name]->dependents ?? [];
    }
}
