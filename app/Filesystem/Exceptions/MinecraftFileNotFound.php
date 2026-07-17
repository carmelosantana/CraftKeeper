<?php

namespace App\Filesystem\Exceptions;

use App\Filesystem\MinecraftPath;
use RuntimeException;

/**
 * Thrown when read() or copyToSnapshot() targets a path that does not
 * currently exist on disk.
 */
class MinecraftFileNotFound extends RuntimeException
{
    public function __construct(MinecraftPath $path)
    {
        parent::__construct(sprintf('No such file: [%s].', $path->relativePath));
    }
}
