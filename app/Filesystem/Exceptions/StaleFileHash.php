<?php

namespace App\Filesystem\Exceptions;

use App\Filesystem\MinecraftPath;
use RuntimeException;

/**
 * Optimistic-concurrency conflict: the caller's expected SHA-256 does not
 * match the file's current on-disk content immediately before the write.
 * This means the file changed outside of (or since) whatever CraftKeeper
 * read that formed the caller's proposal — the write is refused rather
 * than silently overwriting someone else's change. Task 8's
 * ConfigChangeService is expected to catch this and surface it as a
 * reviewable conflict (HTTP 409) rather than a raw 500.
 */
class StaleFileHash extends RuntimeException
{
    public function __construct(
        MinecraftPath $path,
        public readonly string $expectedSha256,
        public readonly string $actualSha256,
    ) {
        parent::__construct(sprintf(
            'Refusing to write [%s]: expected sha256 [%s] but the file is currently [%s].',
            $path->relativePath,
            $expectedSha256,
            $actualSha256,
        ));
    }
}
