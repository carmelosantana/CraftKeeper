<?php

namespace App\Filesystem\Exceptions;

use App\Filesystem\MinecraftPath;
use RuntimeException;

/**
 * Thrown when a resolved Minecraft path exists but is not a regular file —
 * a directory, device, FIFO, or socket. Only regular files may ever be
 * read or written; V1 has no NBT/world-region/arbitrary-binary editing.
 */
class NotARegularFile extends RuntimeException
{
    public function __construct(MinecraftPath $path)
    {
        parent::__construct(sprintf(
            'Refusing to read or write [%s]: it is not a regular file.',
            $path->relativePath,
        ));
    }
}
