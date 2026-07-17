<?php

namespace App\Config\Exceptions;

use App\Filesystem\MinecraftPath;
use RuntimeException;

/**
 * Thrown by ConfigFormatRegistry::for() when no registered
 * ConfigFormatAdapter claims a file — should be unreachable in practice
 * for anything ConfigDiscoveryService returned (it only discovers the
 * four supported extensions), but a defensive, typed failure is safer
 * than an implicit null/crash for any other caller.
 */
class UnsupportedConfigFormat extends RuntimeException
{
    public static function forPath(MinecraftPath $path): self
    {
        return new self(sprintf(
            'No registered configuration format adapter supports [%s].',
            $path->relativePath,
        ));
    }
}
