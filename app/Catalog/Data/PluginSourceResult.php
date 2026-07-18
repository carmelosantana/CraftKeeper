<?php

namespace App\Catalog\Data;

use App\Plugins\PluginProvenance;

/**
 * How ONE source's contribution to a PluginSearchPage went. This is the
 * "labeled degraded result" the brief requires: when a source's live
 * fetch fails, App\Catalog\Sources\AbstractPluginSource::search() never
 * throws — it returns a PluginSearchPage carrying exactly one of these,
 * with $degraded true and $message explaining why, so the page's OTHER
 * sources (and any stale cache this one source itself could still
 * serve) remain visible and clearly attributed rather than the whole
 * search silently failing or silently omitting a source with no
 * explanation.
 */
final readonly class PluginSourceResult
{
    public function __construct(
        public PluginProvenance $source,
        public bool $degraded,
        public ?string $message,
        public bool $servedFromCache,
        public bool $stale,
    ) {}

    public static function ok(PluginProvenance $source, bool $servedFromCache, bool $stale): self
    {
        return new self($source, degraded: false, message: null, servedFromCache: $servedFromCache, stale: $stale);
    }

    public static function degraded(PluginProvenance $source, string $message, bool $servedFromCache, bool $stale): self
    {
        return new self($source, degraded: true, message: $message, servedFromCache: $servedFromCache, stale: $stale);
    }
}
