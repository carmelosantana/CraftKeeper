<?php

namespace App\Catalog;

use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchPage;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Exceptions\PluginSourceException;
use App\Plugins\PluginProvenance;

/**
 * The Stable Interface adapter contract every catalog source
 * (CraftKeeper Catalog, Hangar, Modrinth) implements.
 *
 * search() NEVER THROWS — mirroring App\Plugins\JarInspector's
 * "always returns a result" contract from Task 13, applied to a network
 * source instead of a JAR file. A transport failure (timeout, 5xx after
 * retries, oversized response, malformed body) is caught internally and
 * reported as a labeled-degraded App\Catalog\Data\PluginSourceResult
 * inside the returned PluginSearchPage — never a thrown exception. This
 * is what makes per-source degradation isolation structural rather than
 * something callers have to remember to try/catch: App\Catalog\
 * UnifiedCatalogService::search() can call every registered source in a
 * plain loop with no try/catch at all and still guarantee one source's
 * outage never fails the whole search.
 *
 * release() is a single, targeted lookup by exact identity and is
 * allowed to throw PluginSourceException — there is no "the other
 * releases are still fine" concept for a request that only ever
 * concerned one release. Task 15 (which calls this to resolve a
 * concrete download) is expected to handle that exception itself.
 */
interface PluginSource
{
    public function key(): PluginProvenance;

    public function search(PluginSearchQuery $query): PluginSearchPage;

    /**
     * @throws PluginSourceException
     */
    public function release(PluginReleaseId $id): PluginRelease;
}
