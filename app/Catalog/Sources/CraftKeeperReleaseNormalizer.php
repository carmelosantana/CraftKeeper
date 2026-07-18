<?php

namespace App\Catalog\Sources;

use App\Catalog\Data\PluginDependencyRef;
use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginReleaseSignature;
use App\Plugins\PluginProvenance;
use DateTimeImmutable;
use Throwable;

/**
 * Normalizes ONE (plugin, release) pair from a fetched CraftKeeper
 * Catalog document into a PluginRelease, or returns null if that
 * release is structurally unusable (missing a required field, or a
 * sha256 that is not a valid 64-char hex digest).
 *
 * Deliberately does NOT reuse App\Catalog\CatalogSchemaValidator
 * (the justinrainbow/json-schema-backed validator PluginCatalogContractTest
 * exercises against the whole document). Whole-document schema
 * validation is all-or-nothing — one malformed release among hundreds
 * would mark the ENTIRE document invalid, which is exactly the crash-
 * on-one-bad-release failure mode the brief's "not crashes" requirement
 * rules out. This class re-implements the same required-field/sha256-
 * pattern rules independently, in plain PHP, PER RELEASE, so
 * App\Catalog\Sources\CraftKeeperCatalogSource can skip one bad release
 * and keep every other valid one — the runtime mirror of what
 * PluginCatalogContractTest proves about the schema file itself.
 *
 * A `withdrawn: true` release IS structurally valid (schema-valid) and
 * normalizes successfully here — CraftKeeperCatalogSource is the layer
 * that decides withdrawn releases are excluded from active search
 * results while still being individually resolvable via release().
 */
final class CraftKeeperReleaseNormalizer
{
    private const SHA256_PATTERN = '/^[a-f0-9]{64}$/';

    /**
     * @param  array<string, mixed>  $plugin
     * @param  array<string, mixed>  $release
     */
    public function normalize(array $plugin, array $release): ?PluginRelease
    {
        if (! $this->isStructurallyValid($plugin, $release)) {
            return null;
        }

        return new PluginRelease(
            id: new PluginReleaseId(PluginProvenance::Catalog, $plugin['slug'], $release['version']),
            slug: $plugin['slug'],
            name: $plugin['name'],
            description: $plugin['description'],
            projectUrl: $plugin['projectUrl'],
            sourceUrl: rtrim($plugin['projectUrl'], '/')."#{$release['version']}",
            license: $plugin['license'],
            sourceRepository: $plugin['sourceRepository'],
            version: $release['version'],
            minecraftVersions: array_values($release['minecraftVersions']),
            platforms: array_values($release['platforms']),
            dependencies: array_values(array_map(
                fn (array $d) => new PluginDependencyRef($d['name'], (bool) ($d['required'] ?? false), $d['minVersion'] ?? null),
                $release['dependencies'] ?? [],
            )),
            downloadUrl: $release['downloadUrl'],
            sha256: strtolower($release['sha256']),
            releasedAt: $this->parseDate($release['releasedAt']),
            withdrawn: (bool) ($release['withdrawn'] ?? false),
            signature: isset($release['signature']) ? new PluginReleaseSignature(
                $release['signature']['algorithm'],
                $release['signature']['signature'],
                $release['signature']['keyUrl'],
            ) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $plugin
     * @param  array<string, mixed>  $release
     */
    private function isStructurallyValid(array $plugin, array $release): bool
    {
        foreach (['slug', 'name', 'description', 'projectUrl', 'license', 'sourceRepository'] as $field) {
            if (! isset($plugin[$field]) || $plugin[$field] === '') {
                return false;
            }
        }

        foreach (['version', 'downloadUrl', 'sha256', 'releasedAt'] as $field) {
            if (! isset($release[$field]) || $release[$field] === '') {
                return false;
            }
        }

        if (! preg_match(self::SHA256_PATTERN, strtolower((string) $release['sha256']))) {
            return false;
        }

        if (empty($release['minecraftVersions']) || ! is_array($release['minecraftVersions'])) {
            return false;
        }

        if (empty($release['platforms']) || ! is_array($release['platforms'])) {
            return false;
        }

        return $this->parseDate($release['releasedAt']) !== null;
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
