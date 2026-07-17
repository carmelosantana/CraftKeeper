<?php

namespace App\Filesystem;

/**
 * An immutable pointer to a captured copy of one file's bytes at the time
 * an Operation touched it, stored under
 * {DATA_ROOT}/snapshots/{operationId}/{relativePath}. Produced by
 * SnapshotStore::copy() / MinecraftFilesystem::copyToSnapshot().
 */
final readonly class SnapshotReference
{
    public function __construct(
        public string $operationId,
        public string $relativePath,
        public string $snapshotPath,
        public string $sha256,
        public int $capturedAt,
    ) {}
}
