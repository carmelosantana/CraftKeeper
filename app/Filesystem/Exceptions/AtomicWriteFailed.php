<?php

namespace App\Filesystem\Exceptions;

use App\Filesystem\MinecraftPath;
use RuntimeException;
use Throwable;

/**
 * Thrown when any step of the atomic write sequence (temp-file creation,
 * write, fsync, rename, or the post-rename verification read) fails.
 * App\Filesystem\AtomicFileWriter guarantees that whenever this is thrown,
 * the original file (if any) is untouched and no orphaned temp file is
 * left behind — see AtomicFileWriter::writeLocked() for the cleanup that
 * runs before every throw of this exception.
 */
class AtomicWriteFailed extends RuntimeException
{
    public static function duringRead(MinecraftPath $path): self
    {
        return new self(sprintf('Could not read the current contents of [%s] before writing.', $path->relativePath));
    }

    public static function duringCreate(MinecraftPath $path): self
    {
        return new self(sprintf('Could not create a temporary file next to [%s].', $path->relativePath));
    }

    public static function duringWrite(MinecraftPath $path): self
    {
        return new self(sprintf('Could not write the full contents of [%s] to its temporary file.', $path->relativePath));
    }

    public static function duringFsync(MinecraftPath $path): self
    {
        return new self(sprintf('Could not flush/fsync the temporary file for [%s].', $path->relativePath));
    }

    public static function duringRename(MinecraftPath $path): self
    {
        return new self(sprintf('Could not atomically rename the temporary file into place for [%s].', $path->relativePath));
    }

    public static function verificationMismatch(MinecraftPath $path): self
    {
        return new self(sprintf(
            'Wrote [%s], but the file on disk did not verify against the written content immediately afterward.',
            $path->relativePath,
        ));
    }

    public static function unexpected(MinecraftPath $path, Throwable $previous): self
    {
        return new self(
            sprintf('Unexpected failure writing [%s]: %s', $path->relativePath, $previous->getMessage()),
            previous: $previous,
        );
    }
}
