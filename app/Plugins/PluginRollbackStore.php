<?php

namespace App\Plugins;

use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Models\PluginRollbackArtifact;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Preserves a plugin JAR's CURRENT bytes under
 * {data_root}/plugin-rollbacks/{sanitized-relative-path}/{ulid}.jar
 * BEFORE App\Operations\Handlers\PluginOperationHandler overwrites or
 * removes it — Task 15's ambiguity resolution #3 ("Preserve the replaced
 * JAR under /data/plugin-rollbacks BEFORE overwriting"; "Remove: MOVE to
 * /data/plugin-rollbacks, NEVER unlink immediately"). Deliberately a
 * SEPARATE store from App\Filesystem\SnapshotStore (which serves Task
 * 8's config-file snapshots under {data_root}/snapshots/{operationId}/…):
 * plugin rollback artifacts are grouped and pruned PER PLUGIN across
 * many operations over time ("keep 3 per plugin for 30 days" —
 * App\Console\Commands\PrunePluginRollbackArtifacts), not per a single
 * operation id.
 *
 * Same same-directory-temp-file-then-rename safety SnapshotStore already
 * established, applied to this store's own directory layout.
 */
final class PluginRollbackStore
{
    public function __construct(private readonly MinecraftFilesystem $filesystem) {}

    public function preserve(MinecraftPath $installedPath, string $reason, ?string $operationId): PluginRollbackArtifact
    {
        $snapshot = $this->filesystem->read($installedPath);

        $dir = $this->directoryFor($installedPath->relativePath);
        File::ensureDirectoryExists($dir, 0755);

        $token = (string) Str::ulid();
        $destinationPath = $dir.'/'.$token.'.jar';
        $tempPath = $dir.'/.'.$token.'.ck-tmp';

        if (file_put_contents($tempPath, $snapshot->contents, LOCK_EX) === false) {
            @unlink($tempPath);

            throw new RuntimeException("Could not write rollback artifact for [{$installedPath->relativePath}].");
        }

        if (! @rename($tempPath, $destinationPath)) {
            @unlink($tempPath);

            throw new RuntimeException("Could not finalize rollback artifact for [{$installedPath->relativePath}].");
        }

        return PluginRollbackArtifact::query()->create([
            'relative_path' => $installedPath->relativePath,
            'storage_path' => $destinationPath,
            'sha256' => $snapshot->sha256,
            'size_bytes' => strlen($snapshot->contents),
            'source_operation_id' => $operationId,
            'reason' => $reason,
        ]);
    }

    public function readBytes(PluginRollbackArtifact $artifact): string
    {
        $bytes = @file_get_contents($artifact->storage_path);

        if ($bytes === false) {
            throw new RuntimeException("Rollback artifact bytes are missing at [{$artifact->storage_path}] for [{$artifact->relative_path}].");
        }

        return $bytes;
    }

    private function directoryFor(string $relativePath): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $relativePath) ?? 'plugin';

        return $this->rollbackRoot().'/'.$safe;
    }

    private function rollbackRoot(): string
    {
        return rtrim((string) config('craftkeeper.data_root'), '/').'/plugin-rollbacks';
    }
}
