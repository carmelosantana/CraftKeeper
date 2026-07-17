<?php

namespace App\Config\Exceptions;

use App\Filesystem\MinecraftPath;
use RuntimeException;

/**
 * Thrown by App\Config\ConfigChangeService::propose() when a
 * ConfigChangeRequest's base sha256 does not match the file's real current
 * content — the file changed outside of (or since) whatever CraftKeeper
 * read that formed the caller's proposal. Mirrors
 * App\Filesystem\Exceptions\StaleFileHash (the same optimistic-concurrency
 * failure, one layer up: this is caught at *proposal* time, before an
 * Operation is even created, whereas StaleFileHash is what
 * App\Filesystem\AtomicFileWriter throws if the file changes again between
 * approval and execution — see App\Operations\Handlers\ConfigApplyHandler).
 * Task 9's HTTP layer is expected to map this to a 409 Conflict response.
 */
class ConfigConflict extends RuntimeException
{
    public function __construct(
        MinecraftPath $path,
        public readonly string $expectedSha256,
        public readonly string $actualSha256,
    ) {
        parent::__construct(sprintf(
            'Refusing to propose a change to [%s]: it was based on sha256 [%s], but the file currently hashes to [%s].',
            $path->relativePath,
            $expectedSha256,
            $actualSha256,
        ));
    }
}
