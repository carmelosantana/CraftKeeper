<?php

namespace App\Catalog\Data;

use App\Plugins\PluginProvenance;

/**
 * The EXACT identity a catalog release is deduplicated and merged by —
 * source + the source's own project identifier — deliberately NEVER the
 * display name (see App\Catalog\UnifiedCatalogService's docblock: two
 * distinct Hangar projects, or a Hangar project and a Modrinth project,
 * can legitimately share a display name, and collapsing on name would
 * silently erase one of them). `$version` is optional: null means "the
 * source's own notion of latest" for App\Catalog\PluginSource::release()
 * lookups; a search-result PluginRelease always carries the concrete
 * version it actually represents.
 */
final readonly class PluginReleaseId
{
    public function __construct(
        public PluginProvenance $source,
        public string $projectId,
        public ?string $version = null,
    ) {}

    /**
     * The (source, projectId) pair alone — the dedup/merge key. Two
     * PluginReleaseId values with different $version but the same
     * source+projectId share an identityKey() on purpose: they are the
     * same PROJECT, which is the granularity App\Catalog\
     * UnifiedCatalogService merges search results at.
     */
    public function identityKey(): string
    {
        return $this->source->value.':'.$this->projectId;
    }

    /**
     * @return array{source: string, projectId: string, version: ?string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'projectId' => $this->projectId,
            'version' => $this->version,
        ];
    }

    /**
     * @param  array{source: string, projectId: string, version: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            PluginProvenance::from($data['source']),
            $data['projectId'],
            $data['version'],
        );
    }
}
