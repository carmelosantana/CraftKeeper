<?php

use App\Config\ConfigDiscoveryService;
use App\Config\DiscoveredFile;
use App\Config\DiscoveredFileCategory;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    config(['craftkeeper.minecraft_root' => realpath(base_path('tests/fixtures/minecraft'))]);
});

function discovered_relative_paths(array $discovered): array
{
    return array_map(fn (DiscoveredFile $file) => $file->path->relativePath, $discovered);
}

it('discovers the recognized root-level server files as Built in and recognized', function () {
    $discovered = (new ConfigDiscoveryService)->discover();
    $paths = discovered_relative_paths($discovered);

    expect($paths)->toContain('server.properties', 'ops.json', 'whitelist.json', 'banned-players.json');

    $serverProperties = collect($discovered)->firstWhere('path.relativePath', 'server.properties');

    expect($serverProperties->category)->toBe(DiscoveredFileCategory::Server)
        ->and($serverProperties->provenance)->toBe('Built in')
        ->and($serverProperties->recognized)->toBeTrue()
        ->and($serverProperties->format)->toBe('properties');
});

it('discovers Paper config directory files, distinguishing recognized from generic', function () {
    $discovered = (new ConfigDiscoveryService)->discover();

    $paperGlobal = collect($discovered)->firstWhere('path.relativePath', 'config/paper-global.yml');
    $mystery = collect($discovered)->firstWhere('path.relativePath', 'config/mystery.yml');

    expect($paperGlobal->category)->toBe(DiscoveredFileCategory::Paper)
        ->and($paperGlobal->provenance)->toBe('Built in')
        ->and($paperGlobal->recognized)->toBeTrue();

    expect($mystery->category)->toBe(DiscoveredFileCategory::Paper)
        ->and($mystery->provenance)->toBe('Discovered')
        ->and($mystery->recognized)->toBeFalse();
});

it('discovers Geyser and Floodgate conventional plugin paths as recognized', function () {
    $discovered = (new ConfigDiscoveryService)->discover();

    $geyser = collect($discovered)->firstWhere('path.relativePath', 'plugins/Geyser-Spigot/config.yml');
    $floodgate = collect($discovered)->firstWhere('path.relativePath', 'plugins/floodgate/config.yml');

    expect($geyser->category)->toBe(DiscoveredFileCategory::Geyser)
        ->and($geyser->provenance)->toBe('Plugin')
        ->and($geyser->recognized)->toBeTrue();

    expect($floodgate->category)->toBe(DiscoveredFileCategory::Floodgate)
        ->and($floodgate->provenance)->toBe('Plugin')
        ->and($floodgate->recognized)->toBeTrue();
});

it('discovers a generic plugin config as unrecognized', function () {
    $discovered = (new ConfigDiscoveryService)->discover();

    $example = collect($discovered)->firstWhere('path.relativePath', 'plugins/ExamplePlugin/config.yml');

    expect($example->category)->toBe(DiscoveredFileCategory::Plugin)
        ->and($example->provenance)->toBe('Plugin')
        ->and($example->recognized)->toBeFalse();
});

it('ignores logs, world saves, playerdata, stats, and advancements', function () {
    $paths = discovered_relative_paths((new ConfigDiscoveryService)->discover());

    foreach ($paths as $path) {
        expect($path)->not->toStartWith('logs/')
            ->and($path)->not->toStartWith('world/')
            ->and($path)->not->toStartWith('world_nether/')
            ->and($path)->not->toStartWith('playerdata/')
            ->and($path)->not->toStartWith('stats/')
            ->and($path)->not->toStartWith('advancements/');
    }
});

it('ignores hidden directories, backup directories, and unsupported extensions', function () {
    $paths = discovered_relative_paths((new ConfigDiscoveryService)->discover());

    expect($paths)->not->toContain('plugins/.hidden-plugin/config.yml')
        ->and($paths)->not->toContain('backups/server.properties.bak')
        ->and($paths)->not->toContain('.trash/config.yml')
        ->and($paths)->not->toContain('eula.txt')
        ->and($paths)->not->toContain('README.md')
        ->and($paths)->not->toContain('plugins/ExamplePlugin/extra.conf');
});

it('never descends into or reports a symlinked directory that escapes the root', function () {
    $paths = discovered_relative_paths((new ConfigDiscoveryService)->discover());

    foreach ($paths as $path) {
        expect($path)->not->toStartWith('escape-dir');
    }
});

it('does not report an escaping symlink under its own name either', function () {
    $paths = discovered_relative_paths((new ConfigDiscoveryService)->discover());

    expect($paths)->not->toContain('escape-link.yml');
});

it('discovers every file in a moderately sized tree', function () {
    $root = TempMinecraftRoot::create();

    for ($i = 0; $i < 50; $i++) {
        file_put_contents($root.'/file-'.$i.'.yml', "key: value\n");
    }

    config(['craftkeeper.minecraft_root' => $root]);

    $discovered = (new ConfigDiscoveryService)->discover();

    expect($discovered)->toHaveCount(50);

    TempMinecraftRoot::destroy($root);
});

it('is bounded: an enormous tree is capped rather than returning everything', function () {
    // Couples to ConfigDiscoveryService's internal MAX_FILES (1000) —
    // deliberately, since this test's entire purpose is to prove that
    // bound is real and enforced, not just documented.
    $root = TempMinecraftRoot::create();

    for ($i = 0; $i < 1050; $i++) {
        file_put_contents($root.'/file-'.$i.'.yml', 'key: value');
    }

    config(['craftkeeper.minecraft_root' => $root]);

    $discovered = (new ConfigDiscoveryService)->discover();

    expect(count($discovered))->toBe(1000);

    TempMinecraftRoot::destroy($root);
})->group('slow');

it('excludes files over 2 MiB', function () {
    $root = TempMinecraftRoot::create();
    file_put_contents($root.'/small.yml', "key: value\n");
    file_put_contents($root.'/huge.yml', str_repeat('a', (2 * 1024 * 1024) + 1));

    config(['craftkeeper.minecraft_root' => $root]);

    $paths = discovered_relative_paths((new ConfigDiscoveryService)->discover());

    expect($paths)->toContain('small.yml')
        ->and($paths)->not->toContain('huge.yml');

    TempMinecraftRoot::destroy($root);
});

it('excludes files that look binary despite having a supported extension', function () {
    $root = TempMinecraftRoot::create();
    file_put_contents($root.'/text.yml', "key: value\n");
    file_put_contents($root.'/corrupted.yml', "key: value\0\x01\x02binary-garbage");

    config(['craftkeeper.minecraft_root' => $root]);

    $paths = discovered_relative_paths((new ConfigDiscoveryService)->discover());

    expect($paths)->toContain('text.yml')
        ->and($paths)->not->toContain('corrupted.yml');

    TempMinecraftRoot::destroy($root);
});

it('never parses file contents to classify — even syntactically invalid YAML is still discovered by extension', function () {
    $root = TempMinecraftRoot::create();
    file_put_contents($root.'/broken.yml', 'this: is: not: valid: yaml: [[[');

    config(['craftkeeper.minecraft_root' => $root]);

    $paths = discovered_relative_paths((new ConfigDiscoveryService)->discover());

    expect($paths)->toContain('broken.yml');

    TempMinecraftRoot::destroy($root);
});

it('returns an empty inventory when the configured root does not exist', function () {
    config(['craftkeeper.minecraft_root' => storage_path('craftkeeper-nonexistent-'.uniqid())]);

    expect((new ConfigDiscoveryService)->discover())->toBe([]);
});

it('does not classify plugin folders that merely start with an ignored word as ignored', function () {
    $root = TempMinecraftRoot::create();
    mkdir($root.'/plugins/WorldEdit', 0755, true);
    file_put_contents($root.'/plugins/WorldEdit/config.yml', "enabled: true\n");
    mkdir($root.'/plugins/Statz', 0755, true);
    file_put_contents($root.'/plugins/Statz/config.yml', "enabled: true\n");

    config(['craftkeeper.minecraft_root' => $root]);

    $paths = discovered_relative_paths((new ConfigDiscoveryService)->discover());

    expect($paths)->toContain('plugins/WorldEdit/config.yml', 'plugins/Statz/config.yml');

    TempMinecraftRoot::destroy($root);
});
