<?php

namespace App\Plugins\Exceptions;

use RuntimeException;

/**
 * A downloaded/uploaded artifact was refused for exceeding the configured
 * cap (`craftkeeper.plugins.max_artifact_bytes`) — thrown by
 * App\Plugins\PluginDownloader/PluginUploadService via one of TWO
 * independent checks, mirroring App\Plugins\JarInspector's own
 * declared-size-then-actual-bytes defense:
 *
 *   1. `declared()` — a Content-Length header (download) or the uploaded
 *      file's own reported size (upload) exceeds the cap, checked BEFORE
 *      a single body byte is read/copied.
 *   2. `actual()` — the running total of bytes actually read/written
 *      exceeded the cap DURING streaming, which a dishonest or absent
 *      declared size cannot bypass.
 *
 * In both cases the partial quarantine file is deleted before this is
 * thrown — never left as an orphaned, oversized artifact under
 * {data_root}/quarantine.
 */
class PluginArtifactTooLarge extends RuntimeException
{
    private function __construct(string $message, public readonly int $bytes, public readonly int $maxBytes)
    {
        parent::__construct($message);
    }

    public static function declared(int $declaredBytes, int $maxBytes): self
    {
        return new self(
            "Refusing this artifact: it declares a size of {$declaredBytes} bytes, exceeding the {$maxBytes} byte limit.",
            $declaredBytes,
            $maxBytes,
        );
    }

    public static function actual(int $actualBytes, int $maxBytes): self
    {
        return new self(
            "Refusing this artifact: it exceeded the {$maxBytes} byte limit after {$actualBytes} bytes were read.",
            $actualBytes,
            $maxBytes,
        );
    }
}
