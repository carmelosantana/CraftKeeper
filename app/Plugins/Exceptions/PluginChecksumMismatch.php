<?php

namespace App\Plugins\Exceptions;

use RuntimeException;

/**
 * The Step-1 integrity gate (Task 15): the SHA-256 actually computed while
 * streaming a downloaded/uploaded artifact into quarantine does not match
 * the checksum the caller expected (a catalog release's published
 * `sha256`, for a download). Thrown by App\Plugins\PluginDownloader
 * BEFORE the quarantined bytes are returned to any caller — the partial
 * quarantine file is deleted first (see PluginDownloader::download()) —
 * so there is no path by which a mismatched artifact can ever reach
 * App\Operations\Handlers\PluginOperationHandler, let alone
 * `/minecraft/plugins`.
 */
class PluginChecksumMismatch extends RuntimeException
{
    public function __construct(
        public readonly string $expectedSha256,
        public readonly string $actualSha256,
    ) {
        parent::__construct(sprintf(
            'Refusing to use this artifact: expected sha256 [%s] but the downloaded/uploaded bytes hash to [%s].',
            $expectedSha256,
            $actualSha256,
        ));
    }
}
