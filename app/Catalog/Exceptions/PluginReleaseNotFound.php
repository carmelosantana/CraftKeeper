<?php

namespace App\Catalog\Exceptions;

use App\Catalog\Data\PluginReleaseId;

/**
 * App\Catalog\PluginSource::release() found no matching project/version
 * for the given identity.
 */
final class PluginReleaseNotFound extends PluginSourceException
{
    public static function forId(PluginReleaseId $id): self
    {
        return new self("No release found for {$id->identityKey()}".($id->version !== null ? " @ {$id->version}" : ' (latest)'));
    }
}
