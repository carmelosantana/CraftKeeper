<?php

use App\Filesystem\MinecraftPath;
use App\Plugins\InspectedPlugin;
use App\Plugins\PluginDependencyGraph;

function dependencyGraphPlugin(string $name, array $hard = [], array $soft = []): InspectedPlugin
{
    return new InspectedPlugin(
        path: MinecraftPath::fromUserInput('server.properties'),
        name: $name,
        version: '1.0.0',
        mainClass: null,
        apiVersion: null,
        hardDependencies: $hard,
        softDependencies: $soft,
        metadataSource: 'paper-plugin.yml',
        sha256: str_repeat('a', 64),
        sizeBytes: 1024,
        modifiedAt: time(),
        diagnostics: [],
    );
}

beforeEach(function () {
    config(['craftkeeper.minecraft_root' => realpath(base_path('tests/fixtures/minecraft'))]);
});

it('reports no missing dependencies when every declared dependency is present by name', function () {
    $graph = PluginDependencyGraph::build([
        dependencyGraphPlugin('Vault'),
        dependencyGraphPlugin('Essentials', hard: ['Vault']),
    ]);

    expect($graph->missingHardDependenciesFor('Essentials'))->toBe([])
        ->and($graph->dependentsOf('Vault'))->toBe(['Essentials']);
});

it('reports missing hard and soft dependencies that are not present anywhere in the graph', function () {
    $graph = PluginDependencyGraph::build([
        dependencyGraphPlugin('Essentials', hard: ['Vault'], soft: ['PlaceholderAPI']),
    ]);

    expect($graph->missingHardDependenciesFor('Essentials'))->toBe(['Vault'])
        ->and($graph->missingSoftDependenciesFor('Essentials'))->toBe(['PlaceholderAPI']);
});

it('excludes plugins with no identifiable name from the graph entirely', function () {
    $unnamed = new InspectedPlugin(
        path: MinecraftPath::fromUserInput('server.properties'),
        name: null,
        version: null,
        mainClass: null,
        apiVersion: null,
        hardDependencies: [],
        softDependencies: [],
        metadataSource: null,
        sha256: str_repeat('a', 64),
        sizeBytes: 0,
        modifiedAt: time(),
        diagnostics: [],
    );

    $graph = PluginDependencyGraph::build([$unnamed, dependencyGraphPlugin('Vault')]);

    expect($graph->nodes)->toHaveCount(1)
        ->and($graph->nodes)->toHaveKey('Vault');
});

it('returns empty dependency lists for a name not present in the graph at all, rather than erroring', function () {
    $graph = PluginDependencyGraph::build([dependencyGraphPlugin('Vault')]);

    expect($graph->missingHardDependenciesFor('NeverHeardOfIt'))->toBe([])
        ->and($graph->dependentsOf('NeverHeardOfIt'))->toBe([]);
});
