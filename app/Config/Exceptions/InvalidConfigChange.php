<?php

namespace App\Config\Exceptions;

use App\Config\ConfigChange;
use RuntimeException;

/**
 * Thrown by ConfigFormatAdapter::applyChanges() when a requested change's
 * value cannot be represented in the target format at all (e.g. a
 * multi-line string for a single-line Properties value) — a content
 * problem, not a parser failure, so it is intentionally a distinct
 * exception type from ConfigParseException.
 */
class InvalidConfigChange extends RuntimeException
{
    public static function forChange(ConfigChange $change, string $reason): self
    {
        return new self(sprintf(
            'Cannot apply change to [%s]: %s.',
            $change->path,
            $reason,
        ));
    }
}
