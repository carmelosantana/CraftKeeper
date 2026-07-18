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
 * Adapter for Hangar (hangar.papermc.io/api-docs), PaperMC's own plugin
 * registry. Shape based on Hangar's documented public API v1 as of
 * implementation time — `GET /projects` (search, paginated `result[]`
 * of Project objects) and `GET /projects/{owner}/{slug}` /
 * `GET /projects/{owner}/{slug}/versions[/{name}]` (a specific
 * project's version detail, carrying the concrete per-platform
 * `downloads[platform].fileInfo.sha256Hash`/`downloadUrl`). If the live
 * API's field names drift from what tests/fixtures/catalog/hangar/*.json
 * models, this class's normalize*() methods are the only place that
 * needs to change.
 *
 * search() results are SUMMARIES: Hangar's project-search response does
 * not expose a specific file's hash, only `promotedVersions` (a
 * highlight of the latest version per channel) — so downloadUrl/sha256
 * are null on a search hit; App\Catalog\PluginSource::release() is the
 * authoritative call that resolves them (see App\Catalog\Data\
 * PluginRelease's docblock for why this is deliberate, not a gap).
 *
 * The project identity (App\Catalog\Data\PluginReleaseId::$projectId)
 * is "{owner}/{slug}" — Hangar's own two-part namespace, which is what
 * every Hangar URL is built from.
 */
final class HangarSource extends AbstractPluginSource
{
    public function key(): PluginProvenance
    {
        return PluginProvenance::Hangar;
    }

    protected function cacheKey(PluginSearchQuery $query): string
    {
        return 'catalog:hangar:page:'.md5($query->signature());
    }

    /**
     * @return list<PluginRelease>
     */
    protected function fetchAndNormalize(PluginSearchQuery $query, string $cacheKey, ?CatalogCacheEntry $cached): array
    {
        $url = $this->baseUrl().'/projects';
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

        if (! is_array($decoded) || ! isset($decoded['result']) || ! is_array($decoded['result'])) {
            throw PluginSourceInvalidResponse::forUrl($url, 'expected an object with a "result" array');
        }

        $items = array_values(array_filter(array_map(
            fn (mixed $hit) => is_array($hit) ? $this->normalizeSearchHit($hit) : null,
            $decoded['result'],
        )));

        $this->cache->put($cacheKey, $this->key(), 'page', $this->payloadFromItems($items), $response->etag, $response->lastModified);

        return $items;
    }

    public function release(PluginReleaseId $id): PluginRelease
    {
        [$owner, $slug] = array_pad(explode('/', $id->projectId, 2), 2, '');

        $project = $this->fetchJsonOrFail("{$this->baseUrl()}/projects/{$owner}/{$slug}", [], $id);

        if ($id->version !== null) {
            $version = $this->fetchJsonOrFail("{$this->baseUrl()}/projects/{$owner}/{$slug}/versions/{$id->version}", [], $id);
        } else {
            $versions = $this->fetchJsonOrFail("{$this->baseUrl()}/projects/{$owner}/{$slug}/versions", ['limit' => 1], $id);
            $version = $versions['result'][0] ?? null;

            if (! is_array($version)) {
                throw PluginReleaseNotFound::forId($id);
            }
        }

        return $this->normalizeVersion($project, $version, $owner, $slug);
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function normalizeSearchHit(array $hit): ?PluginRelease
    {
        $owner = $hit['namespace']['owner'] ?? null;
        $slug = $hit['namespace']['slug'] ?? null;

        if (! is_string($owner) || ! is_string($slug) || $owner === '' || $slug === '') {
            return null;
        }

        $promoted = $hit['promotedVersions'][0] ?? [];
        $version = is_string($promoted['version'] ?? null) ? $promoted['version'] : 'unknown';
        $platforms = $this->extractPlatforms($promoted['platforms'] ?? null);
        $minecraftVersions = $this->extractMinecraftVersions($promoted['platformDependencies'] ?? null);

        return new PluginRelease(
            id: new PluginReleaseId(PluginProvenance::Hangar, "{$owner}/{$slug}", $version),
            slug: "{$owner}/{$slug}",
            name: (string) ($hit['name'] ?? $slug),
            description: (string) ($hit['description'] ?? ''),
            projectUrl: "https://hangar.papermc.io/{$owner}/{$slug}",
            sourceUrl: "https://hangar.papermc.io/{$owner}/{$slug}",
            license: $hit['settings']['license']['type'] ?? null,
            sourceRepository: $this->extractSourceRepository($hit),
            version: $version,
            minecraftVersions: $minecraftVersions,
            platforms: $platforms,
            dependencies: [],
            downloadUrl: null,
            sha256: null,
            releasedAt: $this->parseDate($hit['lastUpdated'] ?? null),
            withdrawn: false,
            signature: null,
        );
    }

    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $version
     */
    private function normalizeVersion(array $project, array $version, string $owner, string $slug): PluginRelease
    {
        $downloads = is_array($version['downloads'] ?? null) ? $version['downloads'] : [];
        $platformDependencies = is_array($version['platformDependencies'] ?? null) ? $version['platformDependencies'] : [];
        $platformKeys = array_keys($platformDependencies !== [] ? $platformDependencies : $downloads);
        $primaryPlatform = $platformKeys[0] ?? 'PAPER';
        $download = is_array($downloads[$primaryPlatform] ?? null) ? $downloads[$primaryPlatform] : [];
        $fileInfo = is_array($download['fileInfo'] ?? null) ? $download['fileInfo'] : [];

        $dependencies = [];
        $rawDependencies = $version['dependencies'][$primaryPlatform] ?? [];

        if (is_array($rawDependencies)) {
            foreach ($rawDependencies as $dep) {
                if (is_array($dep) && isset($dep['name'])) {
                    $dependencies[] = new PluginDependencyRef((string) $dep['name'], (bool) ($dep['required'] ?? false));
                }
            }
        }

        $versionName = (string) ($version['name'] ?? 'unknown');

        return new PluginRelease(
            id: new PluginReleaseId(PluginProvenance::Hangar, "{$owner}/{$slug}", $versionName),
            slug: "{$owner}/{$slug}",
            name: (string) ($project['name'] ?? $slug),
            description: (string) ($project['description'] ?? ''),
            projectUrl: "https://hangar.papermc.io/{$owner}/{$slug}",
            sourceUrl: "https://hangar.papermc.io/{$owner}/{$slug}/versions/{$versionName}",
            license: $project['settings']['license']['type'] ?? null,
            sourceRepository: $this->extractSourceRepository($project),
            version: $versionName,
            minecraftVersions: $this->extractMinecraftVersions($platformDependencies),
            platforms: $this->extractPlatforms($platformKeys),
            dependencies: $dependencies,
            downloadUrl: is_string($download['downloadUrl'] ?? null) ? $download['downloadUrl'] : null,
            sha256: is_string($fileInfo['sha256Hash'] ?? null) ? strtolower($fileInfo['sha256Hash']) : null,
            releasedAt: $this->parseDate($version['createdAt'] ?? null),
            withdrawn: false,
            signature: null,
        );
    }

    /**
     * @param  array<string, mixed>  $project
     */
    private function extractSourceRepository(array $project): ?string
    {
        $groups = $project['settings']['links'] ?? [];

        foreach (['top', 'sidebar'] as $group) {
            foreach ($groups[$group] ?? [] as $link) {
                if (is_array($link) && ($link['type'] ?? null) === 'source') {
                    $url = $link['urls'][0] ?? null;

                    return is_string($url) ? $url : null;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractPlatforms(mixed $platforms): array
    {
        if (! is_array($platforms) || $platforms === []) {
            return ['paper'];
        }

        return array_values(array_unique(array_map(
            fn (mixed $p): string => strtolower((string) $p),
            $platforms,
        )));
    }

    /**
     * @return list<string>
     */
    private function extractMinecraftVersions(mixed $platformDependencies): array
    {
        if (! is_array($platformDependencies)) {
            return [];
        }

        $versions = [];

        foreach ($platformDependencies as $list) {
            if (is_array($list)) {
                $versions = array_merge($versions, $list);
            }
        }

        return array_values(array_unique(array_map(strval(...), $versions)));
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
        return rtrim((string) config('catalog.sources.hangar.base_url'), '/');
    }
}
