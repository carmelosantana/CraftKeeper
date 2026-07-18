<?php

namespace App\Support\Api\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller reuses an Idempotency-Key on the same endpoint with
 * a MEANINGFULLY DIFFERENT request body than the one that key was first
 * used with. Rendered as HTTP 409 (bootstrap/app.php) — an unambiguous
 * "this key was already used for a different request" rather than
 * silently returning a proposal that doesn't match what was just asked
 * for, or silently creating a second one.
 */
class IdempotencyKeyConflict extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("The Idempotency-Key [{$idempotencyKey}] was already used with a different request body.");
    }
}
