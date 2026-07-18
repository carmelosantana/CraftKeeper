<?php

namespace App\Plugins\Exceptions;

use RuntimeException;

/**
 * A App\Catalog\Data\PluginRelease was handed to App\Plugins\
 * PluginDownloader with no `downloadUrl`/`sha256` — true for a
 * search-result SUMMARY from Hangar/Modrinth before App\Catalog\
 * PluginSource::release() has resolved a concrete version (see
 * PluginRelease's own docblock). Callers must resolve a release by exact
 * identity first; PluginDownloader never guesses or falls back to a
 * different artifact.
 */
class PluginReleaseMissingArtifact extends RuntimeException
{
    public function __construct(string $releaseIdentity)
    {
        parent::__construct("The release [{$releaseIdentity}] has no resolved download URL/checksum yet — resolve it via PluginSource::release() first.");
    }
}
