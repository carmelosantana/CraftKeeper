<?php

namespace App\Filesystem\Exceptions;

use RuntimeException;

/**
 * Thrown when copyToSnapshot() is given an operation id that is not a safe
 * path segment. Operation ids ultimately become a directory name under
 * {DATA_ROOT}/snapshots/, so this is the same class of containment problem
 * as an unsafe Minecraft path, applied to CraftKeeper's own data root.
 */
class InvalidOperationId extends RuntimeException
{
    public static function make(string $operationId): self
    {
        return new self(sprintf('[%s] is not a valid operation id.', addcslashes($operationId, "\0..\37\177..\377")));
    }
}
