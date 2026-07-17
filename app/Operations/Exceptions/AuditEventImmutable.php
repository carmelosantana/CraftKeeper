<?php

namespace App\Operations\Exceptions;

use RuntimeException;

/**
 * Thrown when application code attempts to update or delete an AuditEvent.
 * The audit trail is append-only at the application layer — see
 * App\Models\AuditEvent::booted().
 */
class AuditEventImmutable extends RuntimeException
{
    public static function forUpdate(): self
    {
        return new self('Audit events are append-only and cannot be updated.');
    }

    public static function forDelete(): self
    {
        return new self('Audit events are append-only and cannot be deleted.');
    }
}
