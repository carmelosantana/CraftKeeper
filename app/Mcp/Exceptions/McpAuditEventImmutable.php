<?php

namespace App\Mcp\Exceptions;

use RuntimeException;

/**
 * Mirrors App\Operations\Exceptions\AuditEventImmutable for
 * App\Models\McpAuditEvent — the MCP audit trail is append-only, exactly
 * like the operation audit trail, and for the same reason: history must
 * never be edited or deleted by any application code path.
 */
class McpAuditEventImmutable extends RuntimeException
{
    public static function forUpdate(): self
    {
        return new self('MCP audit events are append-only and cannot be updated.');
    }

    public static function forDelete(): self
    {
        return new self('MCP audit events are append-only and cannot be deleted.');
    }
}
