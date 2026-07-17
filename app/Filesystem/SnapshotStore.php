<?php

namespace App\Filesystem;

use App\Filesystem\Exceptions\AtomicWriteFailed;
use App\Filesystem\Exceptions\InvalidOperationId;
use App\Filesystem\Exceptions\MinecraftFileNotFound;
use App\Filesystem\Exceptions\NotARegularFile;
use Illuminate\Support\Facades\File;

/**
 * Copies a file's current bytes into CraftKeeper's own data root, keyed by
 * operation id, before a caller (Task 8's ConfigApplyHandler) overwrites
 * it — the "snapshot" half of the plan's "snapshot, then write" order.
 * Snapshots live at {DATA_ROOT}/snapshots/{operationId}/{relativePath},
 * preserving the file's position relative to the Minecraft root so a
 * restore can find it again unambiguously.
 */
class SnapshotStore
{
    public function copy(MinecraftPath $path, string $operationId): SnapshotReference
    {
        $path->reverifyContainment();

        $operationId = $this->sanitizeOperationId($operationId);
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

        $destinationDir = $this->snapshotDirectoryFor($operationId, $path->relativePath);
        File::ensureDirectoryExists($destinationDir, 0755);

        $destinationPath = $destinationDir.'/'.basename($path->relativePath);
        $tempPath = $destinationDir.'/.'.basename($path->relativePath).'.ck-tmp-'.bin2hex(random_bytes(8));

        if (file_put_contents($tempPath, $contents, LOCK_EX) === false) {
            throw AtomicWriteFailed::duringWrite($path);
        }

        if (! rename($tempPath, $destinationPath)) {
            @unlink($tempPath);

            throw AtomicWriteFailed::duringRename($path);
        }

        return new SnapshotReference(
            operationId: $operationId,
            relativePath: $path->relativePath,
            snapshotPath: $destinationPath,
            sha256: hash('sha256', $contents),
            capturedAt: time(),
        );
    }

    private function snapshotDirectoryFor(string $operationId, string $relativePath): string
    {
        $dataRoot = rtrim((string) config('craftkeeper.data_root'), '/');
        $relativeDir = dirname($relativePath);

        $base = $dataRoot.'/snapshots/'.$operationId;

        return $relativeDir === '.' ? $base : $base.'/'.$relativeDir;
    }

    private function sanitizeOperationId(string $operationId): string
    {
        if ($operationId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $operationId) !== 1) {
            throw InvalidOperationId::make($operationId);
        }

        return $operationId;
    }
}
