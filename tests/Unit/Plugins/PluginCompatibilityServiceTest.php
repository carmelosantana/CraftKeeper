<?php

use App\Filesystem\MinecraftPath;
use App\Plugins\InspectedPlugin;
use App\Plugins\PluginCompatibilityService;
use App\Plugins\PluginCompatibilityState;
use App\Plugins\PluginDependencyGraph;
use App\Plugins\PluginInspectionDiagnostic;
use App\Plugins\PluginInspectionIssue;

/**
 * These build InspectedPlugin directly rather than through JarInspector
 * — PluginCompatibilityService's contract is "given this metadata and
 * this inventory context, what's the verdict," independent of how the
 * metadata was obtained.
 */
function plugin(
    ?string $name,
    array $hard = [],
    array $soft = [],
    ?string $apiVersion = null,
    ?string $metadataSource = 'paper-plugin.yml',
    array $diagnostics = [],
): InspectedPlugin {
    return new InspectedPlugin(
        path: MinecraftPath::fromUserInput('server.properties'),
        name: $name,
        version: '1.0.0',
        mainClass: 'com.example.Main',
        apiVersion: $apiVersion,
        hardDependencies: $hard,
        softDependencies: $soft,
        metadataSource: $metadataSource,
        sha256: str_repeat('a', 64),
        sizeBytes: 1024,
        modifiedAt: time(),
        diagnostics: $diagnostics,
    );
}

beforeEach(function () {
    config(['craftkeeper.minecraft_root' => realpath(base_path('tests/fixtures/minecraft'))]);
    $this->service = new PluginCompatibilityService;
});

it('never infers Compatible merely because a JAR has valid, readable metadata', function () {
    $subject = plugin('SelfContained');
    $graph = PluginDependencyGraph::build([$subject]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Unknown);
});

it('stays Unknown when there is no dependency or api-version evidence at all', function () {
    $subject = plugin('NoEvidence');
    $graph = PluginDependencyGraph::build([]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Unknown)
        ->and($assessment->evidence)->not->toBeEmpty(); // still explains itself
});

it('is Incompatible when a declared hard dependency is missing from the inventory', function () {
    $subject = plugin('NeedsVault', hard: ['Vault']);
    $graph = PluginDependencyGraph::build([$subject]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Incompatible)
        ->and(collect($assessment->evidence)->pluck('summary')->implode(' '))->toContain('Vault');
});

it('is Compatible when every declared hard dependency is present in the inventory', function () {
    $vault = plugin('Vault');
    $subject = plugin('NeedsVault', hard: ['Vault']);
    $graph = PluginDependencyGraph::build([$vault, $subject]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Compatible);
});

it('is Warning when an optional soft dependency is missing but nothing else is wrong', function () {
    $subject = plugin('WantsPlaceholderAPI', soft: ['PlaceholderAPI']);
    $graph = PluginDependencyGraph::build([$subject]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Warning);
});

it('lets a missing hard dependency take precedence over a merely-missing soft dependency', function () {
    $subject = plugin('NeedsVault', hard: ['Vault'], soft: ['PlaceholderAPI']);
    $graph = PluginDependencyGraph::build([$subject]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Incompatible);
});

it('is Incompatible when the declared api-version is newer than the known server api-version', function () {
    $subject = plugin('TooNew', apiVersion: '1.21');
    $graph = PluginDependencyGraph::build([$subject]);

    $assessment = $this->service->evaluate($subject, $graph, serverApiVersion: '1.20');

    expect($assessment->state)->toBe(PluginCompatibilityState::Incompatible);
});

it('is Compatible when the declared api-version is satisfied by the known server api-version', function () {
    $subject = plugin('FineHere', apiVersion: '1.20');
    $graph = PluginDependencyGraph::build([$subject]);

    $assessment = $this->service->evaluate($subject, $graph, serverApiVersion: '1.21');

    expect($assessment->state)->toBe(PluginCompatibilityState::Compatible);
});

it('stays Unknown for a declared api-version when the running server api-version is not supplied', function () {
    $subject = plugin('CantCompare', apiVersion: '1.21');
    $graph = PluginDependencyGraph::build([$subject]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Unknown);
});

it('is Incompatible when the archive looks like a Velocity/BungeeCord plugin, not a Paper plugin', function () {
    $subject = plugin(
        null,
        metadataSource: null,
        diagnostics: [new PluginInspectionDiagnostic(PluginInspectionIssue::ForeignPlatform, 'Velocity descriptor found, no Paper metadata.')],
    );
    $graph = PluginDependencyGraph::build([]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Incompatible);
});

it('stays Unknown (not Warning or Incompatible) for a plain missing-metadata diagnostic', function () {
    $subject = plugin(
        null,
        metadataSource: null,
        diagnostics: [new PluginInspectionDiagnostic(PluginInspectionIssue::NoMetadata, 'No metadata found.')],
    );
    $graph = PluginDependencyGraph::build([]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->state)->toBe(PluginCompatibilityState::Unknown);
});

it('always returns evidence alongside the verdict, never a bare state', function () {
    $vault = plugin('Vault');
    $subject = plugin('NeedsVault', hard: ['Vault']);
    $graph = PluginDependencyGraph::build([$vault, $subject]);

    $assessment = $this->service->evaluate($subject, $graph);

    expect($assessment->evidence)->not->toBeEmpty()
        ->and($assessment->evidence[0]->source)->toBeString()
        ->and($assessment->evidence[0]->summary)->toBeString();
});
