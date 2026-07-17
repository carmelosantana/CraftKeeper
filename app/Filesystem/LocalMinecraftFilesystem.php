<?php

namespace App\Filesystem;

use App\Config\ConfigDiscoveryService;
use App\Config\DiscoveredFile;
use App\Filesystem\Exceptions\AtomicWriteFailed;
use App\Filesystem\Exceptions\MinecraftFileNotFound;
use App\Filesystem\Exceptions\NotARegularFile;

/**
 * The concrete, local-disk MinecraftFilesystem: everything is composed
 * from the smaller, individually-tested primitives in this namespace
 * (ConfigDiscoveryService, AtomicFileWriter, SnapshotStore) rather than
 * re-implemented here.
 */
final class LocalMinecraftFilesystem implements MinecraftFilesystem
{
    public function __construct(
        private readonly ConfigDiscoveryService $discovery,
        private readonly AtomicFileWriter $writer,
        private readonly SnapshotStore $snapshots,
    ) {}

    /**
     * @return list<DiscoveredFile>
     */
    public function discover(): array
    {
        return $this->discovery->discover();
    }

    public function read(MinecraftPath $path): FileSnapshot
    {
        $path->reverifyContainment();

        $absolute = $path->absolutePath;

        if (! file_exists($absolute)) {
            throw new MinecraftFileNotFound($path);
        }

        if (filetype($absolute) !== 'file') {
            throw new NotARegularFile($path);
        }

        $contents = file_get_contents($absolute);

        if ($contents === false) {
            throw AtomicWriteFailed::duringRead($path);
        }

        return new FileSnapshot(
            $path,
            $contents,
            hash('sha256', $contents),
            fileperms($absolute) & 0777,
            filemtime($absolute) ?: time(),
        );
    }

    public function writeAtomically(MinecraftPath $path, string $contents, string $expectedSha256): FileSnapshot
    {
        return $this->writer->write($path, $contents, $expectedSha256);
    }

    public function copyToSnapshot(MinecraftPath $path, string $operationId): SnapshotReference
    {
        return $this->snapshots->copy($path, $operationId);
    }
}
