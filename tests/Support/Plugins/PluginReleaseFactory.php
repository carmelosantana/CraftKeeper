<?php

namespace Tests\Support\Plugins;

use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Plugins\PluginProvenance;

/**
 * Test-only helper: builds a real App\Catalog\Data\PluginRelease directly
 * through its actual constructor, since the brief's own Step-1 test
 * snippet uses an illustrative `PluginRelease::fromArray([...])` shape
 * that predates — and does not match — the real Task 14 shape (see
 * docs/architecture/decisions.md, Task 15). A single shared factory
 * (rather than a same-named helper function duplicated per test file,
 * which Pest/PHPUnit's single-process execution would fatal on
 * redeclaring) keeps every plugin lifecycle test building releases the
 * exact same way.
 */
final class PluginReleaseFactory
{
    public static function make(
        string $projectId = 'example',
        string $version = '1.0.0',
        ?string $sha256 = null,
        ?string $downloadUrl = null,
        string $name = 'Example',
        PluginProvenance $source = PluginProvenance::Catalog,
    ): PluginRelease {
        return new PluginRelease(
            id: new PluginReleaseId($source, $projectId, $version),
            slug: $projectId,
            name: $name,
            description: 'A test plugin release.',
            projectUrl: "https://catalog.example/plugins/{$projectId}",
            sourceUrl: "https://catalog.example/plugins/{$projectId}",
            license: 'MIT',
            sourceRepository: null,
            version: $version,
            minecraftVersions: ['1.21.8'],
            platforms: ['paper'],
            dependencies: [],
            downloadUrl: $downloadUrl ?? "https://catalog.example/plugins/{$projectId}-{$version}.jar",
            sha256: $sha256 ?? str_repeat('a', 64),
            releasedAt: null,
            withdrawn: false,
            signature: null,
        );
    }
}
