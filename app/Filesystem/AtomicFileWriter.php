<?php

namespace App\Filesystem;

use App\Filesystem\Exceptions\AtomicWriteFailed;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\ParentDirectoryMissing;
use App\Filesystem\Exceptions\StaleFileHash;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Writes one file inside the Minecraft root atomically and with optimistic
 * concurrency control. For every write, in order:
 *
 *  1. Acquire a per-path lock (a flock()'d file under
 *     {DATA_ROOT}/locks/ — never inside /minecraft itself, since only
 *     CraftKeeper's own state belongs under /data).
 *  2. Re-verify the path still resolves inside the Minecraft root
 *     (MinecraftPath::reverifyContainment() — narrows, see its docblock,
 *     the check-then-use window since the path was first resolved).
 *  3. Re-read the file's *current* content and compare its SHA-256 against
 *     the caller's $expectedSha256; a mismatch throws StaleFileHash before
 *     anything is written (optimistic concurrency — a file that does not
 *     yet exist is treated as sha256('') for this comparison).
 *  4. Write the new bytes to a fresh, same-directory temporary file
 *     (O_EXCL — never overwrites an existing temp file), fsync it, and
 *     copy the original file's mode/ownership onto it where the OS
 *     permits (chmod/chown/chgrp are all run with error suppression: a
 *     non-root process legitimately cannot chown, and that must not fail
 *     the write).
 *  5. rename() the temp file over the target — atomic on the same
 *     filesystem, which same-directory placement guarantees.
 *  6. Re-read the file and verify its hash matches what was written.
 *  7. Release the lock.
 *
 * On ANY failure after the temp file is created, it is deleted before the
 * exception propagates — the original file is never left partially
 * written, and no orphan temp file survives the call.
 *
 * fsync()/rename() are called through protected, overridable methods
 * purely so tests can deterministically simulate an OS-level interruption
 * at exactly those points (there is no portable, deterministic way to
 * force a real partial write from a black-box PHP test). Production code
 * only ever uses the real syscalls; nothing else about the write path is
 * mocked.
 *
 * Deliberately out of scope here (left to Task 8's ConfigApplyHandler,
 * which composes this with SnapshotStore): capturing a pre-write snapshot,
 * and content validation. The writeAtomically() contract has no
 * operationId parameter, so it cannot snapshot on the caller's behalf.
 */
class AtomicFileWriter
{
    public function write(MinecraftPath $path, string $contents, string $expectedSha256): FileSnapshot
    {
        $lock = $this->acquireLock($path);

        try {
            return $this->writeLocked($path, $contents, $expectedSha256);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function writeLocked(MinecraftPath $path, string $contents, string $expectedSha256): FileSnapshot
    {
        $path->reverifyContainment();

        $absolute = $path->absolutePath;
        $parentDir = dirname($absolute);

        if (! is_dir($parentDir)) {
            throw new ParentDirectoryMissing($path);
        }

        $existsNow = file_exists($absolute);

        if ($existsNow && filetype($absolute) !== 'file') {
            throw new NotARegularFile($path);
        }

        $currentContents = $existsNow ? file_get_contents($absolute) : '';

        if ($currentContents === false) {
            throw AtomicWriteFailed::duringRead($path);
        }

        $currentSha256 = hash('sha256', $currentContents);

        if (! hash_equals($expectedSha256, $currentSha256)) {
            throw new StaleFileHash($path, $expectedSha256, $currentSha256);
        }

        $preservedMode = $existsNow ? (fileperms($absolute) & 0777) : 0644;
        $ownerUid = $existsNow ? @fileowner($absolute) : false;
        $ownerGid = $existsNow ? @filegroup($absolute) : false;

        $tempPath = $this->makeTempPath($parentDir, basename($absolute));

        $this->writeTempFile($path, $tempPath, $contents, $preservedMode, $ownerUid, $ownerGid, $absolute);

        clearstatcache(true, $absolute);
        $finalContents = file_get_contents($absolute);
        $expectedFinalSha256 = hash('sha256', $contents);

        if ($finalContents === false || ! hash_equals($expectedFinalSha256, hash('sha256', $finalContents))) {
            throw AtomicWriteFailed::verificationMismatch($path);
        }

        return new FileSnapshot(
            $path,
            $finalContents,
            $expectedFinalSha256,
            fileperms($absolute) & 0777,
            filemtime($absolute) ?: time(),
        );
    }

    private function writeTempFile(
        MinecraftPath $path,
        string $tempPath,
        string $contents,
        int $preservedMode,
        int|false $ownerUid,
        int|false $ownerGid,
        string $absolute,
    ): void {
        $handle = @fopen($tempPath, 'xb');

        if ($handle === false) {
            throw AtomicWriteFailed::duringCreate($path);
        }

        try {
            try {
                $bytesWritten = fwrite($handle, $contents);

                if ($bytesWritten === false || $bytesWritten < strlen($contents)) {
                    throw AtomicWriteFailed::duringWrite($path);
                }

                if (! fflush($handle) || ! $this->fsyncHandle($handle)) {
                    throw AtomicWriteFailed::duringFsync($path);
                }
            } finally {
                fclose($handle);
            }

            @chmod($tempPath, $preservedMode);

            if ($ownerUid !== false) {
                @chown($tempPath, $ownerUid);
            }

            if ($ownerGid !== false) {
                @chgrp($tempPath, $ownerGid);
            }

            if (! $this->renameFile($tempPath, $absolute)) {
                throw AtomicWriteFailed::duringRename($path);
            }
        } catch (Throwable $e) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }

            throw match (true) {
                $e instanceof AtomicWriteFailed => $e,
                default => AtomicWriteFailed::unexpected($path, $e),
            };
        }
    }

    private function makeTempPath(string $parentDir, string $basename): string
    {
        return $parentDir.'/.'.$basename.'.ck-tmp-'.bin2hex(random_bytes(8));
    }

    /**
     * @param  resource  $handle
     */
    protected function fsyncHandle($handle): bool
    {
        return fsync($handle);
    }

    protected function renameFile(string $from, string $to): bool
    {
        return rename($from, $to);
    }

    /**
     * @return resource
     */
    private function acquireLock(MinecraftPath $path)
    {
        $locksDir = rtrim((string) config('craftkeeper.data_root'), '/').'/locks';

        if (! is_dir($locksDir)) {
            File::makeDirectory($locksDir, 0755, true, true);
        }

        $lockFile = $locksDir.'/'.hash('sha256', $path->absolutePath).'.lock';
        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException("Unable to open lock file for [{$path->relativePath}].");
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new RuntimeException("Unable to acquire a write lock for [{$path->relativePath}].");
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
