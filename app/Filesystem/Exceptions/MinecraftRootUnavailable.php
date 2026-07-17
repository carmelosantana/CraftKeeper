<?php

namespace App\Filesystem\Exceptions;

use RuntimeException;

/**
 * Thrown when config('craftkeeper.minecraft_root') does not resolve to a
 * real, existing directory — a misconfiguration, not a security escape.
 * CraftKeeper never operates against a phantom root: no path can be judged
 * "contained" without first canonicalizing the root itself.
 */
class MinecraftRootUnavailable extends RuntimeException
{
    public static function make(string $configuredPath): self
    {
        return new self(sprintf(
            'The configured Minecraft root [%s] does not exist or is not a directory.',
            $configuredPath,
        ));
    }
}
