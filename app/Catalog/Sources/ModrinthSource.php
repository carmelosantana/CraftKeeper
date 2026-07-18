<?php

namespace App\Catalog\Sources;

use App\Catalog\Data\PluginDependencyRef;
use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Exceptions\PluginReleaseNotFound;
use App\Catalog\Exceptions\PluginSourceInvalidResponse;
use App\Models\CatalogCacheEntry;
use App\Plugins\PluginProvenance;
use DateTimeImmutable;

/**
 * Adapter for Modrinth (docs.modrinth.com/api). Shape based on
 * Modrinth's documented public API v2 as of implementation time —
 * `GET /search` (paginated `hits[]`, one flat summary object per
 * project) and `GET /project/{id|slug}` + `GET /project/{id|slug}/version`
 * (a specific project's version list, each carrying
 * `files[].hashes.sha256`/`files[].url`). As with App\Catalog\Sources\
 * HangarSource, if the live API's field names drift from what
 * tests/fixtures/catalog/modrinth/*.json models, this class's
 * normalize*() methods are the only place that needs to change.
 *
 * search() results are SUMMARIES for the same reason as Hangar's: a
 * search hit exposes `latest_version` as a version NUMBER string but no
 * file hash — downloadUrl/sha256 are null until release() resolves a
 * concrete version.
 *
 * The project identity (App\Catalog\Data\PluginReleaseId::$projectId)
 * is the project's `slug` (Modrinth guarantees slug uniqueness, and
 * every Modrinth URL/endpoint accepts it interchangeably with the
 * opaque base62 project id).
 *
 * Platform (loader) detection: Modrinth's search-hit schema has no
 * dedicated "platforms" field for plugin-type projects — loader tags
 * (paper/spigot/bukkit/folia/velocity/bungeecord) are mixed into the
 * same `categories` array as genuine content categories (economy,
 * utility, ...). extractPlatforms() intersects `categories` against
 * the schema's known platform enum rather than guessing.
 */
final class ModrinthSource extends AbstractPluginSource
{
    private const KNOWN_PLATFORMS = ['paper', 'spigot', 'bukkit', 'folia', 'velocity', 'bungeecord'];

    public function key(): PluginProvenance
    {
        return PluginProvenance::Modrinth;
    }

    protected function cacheKey(PluginSearchQuery $query): string
    {
        return 'catalog:modrinth:page:'.md5($query->signature());
    }

    /**
     * @return list<PluginRelease>
     */
    protected function fetchAndNormalize(PluginSearchQuery $query, string $cacheKey, ?CatalogCacheEntry $cached): array
    {
        $url = $this->baseUrl().'/search';
        $params = array_filter([
            'query' => $query->query,
            'limit' => $query->perPage,
            'offset' => max(0, ($query->page - 1) * $query->perPage),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $this->http->get($url, $params, $cached?->etag, $cached?->last_modified);

        if ($response->notModified) {
            $this->cache->touchFreshness($cached);

            return $this->itemsFromPayload($cached->payload);
        }

        $decoded = json_decode($response->body, true);

        if (! is_array($decoded) || ! isset($decoded['hits']) || ! is_array($decoded['hits'])) {
            throw PluginSourceInvalidResponse::forUrl($url, 'expected an object with a "hits" array');
        }

        $items = array_values(array_filter(array_map(
            fn (mixed $hit) => is_array($hit) ? $this->normalizeSearchHit($hit) : null,
            $decoded['hits'],
        )));

        $this->cache->put($cacheKey, $this->key(), 'page', $this->payloadFromItems($items), $response->etag, $response->lastModified);

        return $items;
    }

    public function release(PluginReleaseId $id): PluginRelease
    {
        $slug = $id->projectId;
        $project = $this->fetchJsonOrFail("{$this->baseUrl()}/project/{$slug}", [], $id);
        $versions = $this->fetchJsonOrFail("{$this->baseUrl()}/project/{$slug}/version", [], $id);

        if (! array_is_list($versions)) {
            throw PluginSourceInvalidResponse::forUrl("{$this->baseUrl()}/project/{$slug}/version", 'expected a JSON array of versions');
        }

        $version = null;

        if ($id->version !== null) {
            foreach ($versions as $candidate) {
                if (is_array($candidate) && ($candidate['version_number'] ?? null) === $id->version) {
                    $version = $candidate;
                    break;
                }
            }
        } else {
            $version = $versions[0] ?? null;
        }

        if (! is_array($version)) {
            throw PluginReleaseNotFound::forId($id);
        }

        return $this->normalizeVersion($project, $version, $slug);
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function normalizeSearchHit(array $hit): ?PluginRelease
    {
        $slug = $hit['slug'] ?? null;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $version = is_string($hit['latest_version'] ?? null) ? $hit['latest_version'] : 'unknown';
        $minecraftVersions = is_array($hit['versions'] ?? null)
            ? array_values(array_map(strval(...), $hit['versions']))
            : [];

        return new PluginRelease(
            id: new PluginReleaseId(PluginProvenance::Modrinth, $slug, $version),
            slug: $slug,
            name: (string) ($hit['title'] ?? $slug),
            description: (string) ($hit['description'] ?? ''),
            projectUrl: "https://modrinth.com/plugin/{$slug}",
            sourceUrl: "https://modrinth.com/plugin/{$slug}",
            license: is_string($hit['license'] ?? null) ? $hit['license'] : null,
            sourceRepository: null,
            version: $version,
            minecraftVersions: $minecraftVersions,
            platforms: $this->extractPlatforms($hit['categories'] ?? []),
            dependencies: [],
            downloadUrl: null,
            sha256: null,
            releasedAt: $this->parseDate($hit['date_modified'] ?? null),
            withdrawn: false,
            signature: null,
        );
    }

    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $version
     */
    private function normalizeVersion(array $project, array $version, string $slug): PluginRelease
    {
        $files = is_array($version['files'] ?? null) ? $version['files'] : [];
        $primaryFile = null;

        foreach ($files as $file) {
            if (is_array($file) && ($file['primary'] ?? false) === true) {
                $primaryFile = $file;
                break;
            }
        }

        $primaryFile ??= (is_array($files[0] ?? null) ? $files[0] : []);
        $hashes = is_array($primaryFile['hashes'] ?? null) ? $primaryFile['hashes'] : [];

        $dependencies = [];
        $rawDependencies = is_array($version['dependencies'] ?? null) ? $version['dependencies'] : [];

        foreach ($rawDependencies as $dep) {
            if (is_array($dep) && isset($dep['project_id'])) {
                $dependencies[] = new PluginDependencyRef(
                    (string) $dep['project_id'],
                    ($dep['dependency_type'] ?? null) === 'required',
                );
            }
        }

        $versionNumber = (string) ($version['version_number'] ?? 'unknown');
        $licenseField = $project['license'] ?? null;
        $license = is_array($licenseField) ? ($licenseField['id'] ?? null) : $licenseField;

        return new PluginRelease(
            id: new PluginReleaseId(PluginProvenance::Modrinth, $slug, $versionNumber),
            slug: $slug,
            name: (string) ($project['title'] ?? $slug),
            description: (string) ($project['description'] ?? ''),
            projectUrl: "https://modrinth.com/plugin/{$slug}",
            sourceUrl: "https://modrinth.com/plugin/{$slug}/version/{$versionNumber}",
            license: is_string($license) ? $license : null,
            sourceRepository: is_string($project['source_url'] ?? null) ? $project['source_url'] : null,
            version: $versionNumber,
            minecraftVersions: is_array($version['game_versions'] ?? null)
                ? array_values(array_map(strval(...), $version['game_versions']))
                : [],
            platforms: is_array($version['loaders'] ?? null) && $version['loaders'] !== []
                ? array_values(array_map(fn (mixed $l): string => strtolower((string) $l), $version['loaders']))
                : ['paper'],
            dependencies: $dependencies,
            downloadUrl: is_string($primaryFile['url'] ?? null) ? $primaryFile['url'] : null,
            sha256: is_string($hashes['sha256'] ?? null) ? strtolower($hashes['sha256']) : null,
            releasedAt: $this->parseDate($version['date_published'] ?? null),
            withdrawn: false,
            signature: null,
        );
    }

    /**
     * @return list<string>
     */
    private function extractPlatforms(mixed $categories): array
    {
        if (! is_array($categories)) {
            return [];
        }

        $matched = array_values(array_intersect(
            array_map(fn (mixed $c): string => strtolower((string) $c), $categories),
            self::KNOWN_PLATFORMS,
        ));

        return $matched;
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('catalog.sources.modrinth.base_url'), '/');
    }
}
