<?php

namespace App\Catalog\Data;

/**
 * The input to every App\Catalog\PluginSource::search() and
 * App\Catalog\UnifiedCatalogService::search() call. Deliberately narrow:
 * free-text query plus the two filters every source can reason about
 * (a target Minecraft version, and a target platform). Nothing here
 * requests a specific source — UnifiedCatalogService always asks every
 * registered source and merges (see its docblock for why "just query
 * one source" isn't exposed at this layer).
 */
final readonly class PluginSearchQuery
{
    public function __construct(
        public ?string $query = null,
        public ?string $minecraftVersion = null,
        public ?string $platform = null,
        public int $page = 1,
        public int $perPage = 20,
    ) {}

    /**
     * A stable, order-independent string key for this query — used as
     * (part of) a per-source cache key. Two PluginSearchQuery instances
     * with the same field values always produce the same signature.
     */
    public function signature(): string
    {
        return implode('|', [
            'q='.($this->query ?? ''),
            'mc='.($this->minecraftVersion ?? ''),
            'platform='.($this->platform ?? ''),
            'page='.$this->page,
            'perPage='.$this->perPage,
        ]);
    }
}
