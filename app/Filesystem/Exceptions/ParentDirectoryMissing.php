<?php

namespace App\Filesystem\Exceptions;

use App\Filesystem\MinecraftPath;
use RuntimeException;

/**
 * Thrown when writeAtomically() targets a path whose containing directory
 * does not exist. CraftKeeper never creates arbitrary directory trees
 * inside the mounted Minecraft volume on the caller's behalf — the parent
 * directory (e.g. a plugin's own folder) must already exist.
 */
class ParentDirectoryMissing extends RuntimeException
{
    public function __construct(MinecraftPath $path)
    {
        parent::__construct(sprintf(
            'Cannot write [%s]: its containing directory does not exist.',
            $path->relativePath,
        ));
    }
}
