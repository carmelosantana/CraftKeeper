<?php

namespace App\Catalog\Data;

use App\Plugins\PluginCompatibilityEvidence;
use App\Plugins\PluginProvenance;
use DateTimeImmutable;

/**
 * The one normalized shape every App\Catalog\PluginSource adapter maps
 * its source's own response into — the "Stable Interface" DTO named in
 * the brief. `$id->source` / `$sourceUrl` are the "source badge" and
 * "source URL" the brief requires never be erased when results are
 * merged (App\Catalog\UnifiedCatalogService::search() never collapses
 * two PluginRelease values from different sources into one, precisely
 * so this is never lost — see that class's docblock).
 *
 * `$downloadUrl`/`$sha256` are nullable: a source's SEARCH results may
 * only carry summary information (Hangar and Modrinth's list/search
 * endpoints do not expose a specific file's hash — only their
 * per-version detail endpoint does), in which case a search hit carries
 * null here and App\Catalog\PluginSource::release() is the
 * authoritative call that resolves them. App\Catalog\Sources\
 * CraftKeeperCatalogSource's search results always have both populated,
 * because its one JSON document already contains them.
 *
 * `$compatibilityEvidence` lets a release carry catalog-sourced
 * compatibility signal (e.g. "declares support for the Minecraft
 * version you searched for") using the SAME evidence vocabulary
 * App\Plugins\PluginCompatibilityService already established in Task
 * 13 (App\Plugins\PluginCompatibilityEvidence) — this task does not
 * change that service's signature; it just reuses its evidence shape
 * so a later task can fold catalog evidence and inventory evidence
 * together without inventing a second vocabulary.
 */
final readonly class PluginRelease
{
    /**
     * @param  list<string>  $minecraftVersions
     * @param  list<string>  $platforms
     * @param  list<PluginDependencyRef>  $dependencies
     * @param  list<PluginCompatibilityEvidence>  $compatibilityEvidence
     */
    public function __construct(
        public PluginReleaseId $id,
        public string $slug,
        public string $name,
        public string $description,
        public string $projectUrl,
        public string $sourceUrl,
        public ?string $license,
        public ?string $sourceRepository,
        public string $version,
        public array $minecraftVersions,
        public array $platforms,
        public array $dependencies,
        public ?string $downloadUrl,
        public ?string $sha256,
        public ?DateTimeImmutable $releasedAt,
        public bool $withdrawn,
        public ?PluginReleaseSignature $signature,
        public array $compatibilityEvidence = [],
    ) {}

    public function source(): PluginProvenance
    {
        return $this->id->source;
    }

    /**
     * @param  list<PluginCompatibilityEvidence>  $evidence
     */
    public function withCompatibilityEvidence(array $evidence): self
    {
        return new self(
            id: $this->id,
            slug: $this->slug,
            name: $this->name,
            description: $this->description,
            projectUrl: $this->projectUrl,
            sourceUrl: $this->sourceUrl,
            license: $this->license,
            sourceRepository: $this->sourceRepository,
            version: $this->version,
            minecraftVersions: $this->minecraftVersions,
            platforms: $this->platforms,
            dependencies: $this->dependencies,
            downloadUrl: $this->downloadUrl,
            sha256: $this->sha256,
            releasedAt: $this->releasedAt,
            withdrawn: $this->withdrawn,
            signature: $this->signature,
            compatibilityEvidence: $evidence,
        );
    }

    /**
     * Round-trips through App\Catalog\CatalogCache's JSON `payload`
     * column — see fromArray(). Every nested value object here is owned
     * by this task except PluginCompatibilityEvidence (Task 13's), which
     * is converted inline rather than by adding methods to a file this
     * task does not own.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toArray(),
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'projectUrl' => $this->projectUrl,
            'sourceUrl' => $this->sourceUrl,
            'license' => $this->license,
            'sourceRepository' => $this->sourceRepository,
            'version' => $this->version,
            'minecraftVersions' => $this->minecraftVersions,
            'platforms' => $this->platforms,
            'dependencies' => array_map(fn (PluginDependencyRef $d) => $d->toArray(), $this->dependencies),
            'downloadUrl' => $this->downloadUrl,
            'sha256' => $this->sha256,
            'releasedAt' => $this->releasedAt?->format(DATE_ATOM),
            'withdrawn' => $this->withdrawn,
            'signature' => $this->signature?->toArray(),
            'compatibilityEvidence' => array_map(
                fn (PluginCompatibilityEvidence $e) => [
                    'source' => $e->source,
                    'summary' => $e->summary,
                    'supportsCompatibility' => $e->supportsCompatibility,
                ],
                $this->compatibilityEvidence,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: PluginReleaseId::fromArray($data['id']),
            slug: $data['slug'],
            name: $data['name'],
            description: $data['description'],
            projectUrl: $data['projectUrl'],
            sourceUrl: $data['sourceUrl'],
            license: $data['license'],
            sourceRepository: $data['sourceRepository'],
            version: $data['version'],
            minecraftVersions: $data['minecraftVersions'],
            platforms: $data['platforms'],
            dependencies: array_values(array_map(fn (array $d) => PluginDependencyRef::fromArray($d), $data['dependencies'])),
            downloadUrl: $data['downloadUrl'],
            sha256: $data['sha256'],
            releasedAt: $data['releasedAt'] !== null ? new DateTimeImmutable($data['releasedAt']) : null,
            withdrawn: $data['withdrawn'],
            signature: $data['signature'] !== null ? PluginReleaseSignature::fromArray($data['signature']) : null,
            compatibilityEvidence: array_values(array_map(
                fn (array $e) => new PluginCompatibilityEvidence($e['source'], $e['summary'], $e['supportsCompatibility']),
                $data['compatibilityEvidence'] ?? [],
            )),
        );
    }
}
