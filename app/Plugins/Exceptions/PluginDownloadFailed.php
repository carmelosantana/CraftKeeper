<?php

namespace App\Plugins\Exceptions;

use RuntimeException;

/**
 * A transport-level failure while streaming a release's artifact
 * (connection failure, non-2xx response, or a local I/O failure writing
 * the quarantine temp file) — distinct from App\Plugins\Exceptions\
 * PluginChecksumMismatch/PluginArtifactTooLarge, which mean the transfer
 * completed but the bytes themselves were refused. Thrown by
 * App\Plugins\PluginDownloader; the partial quarantine file is always
 * deleted first.
 */
class PluginDownloadFailed extends RuntimeException
{
    public function __construct(string $url, string $reason)
    {
        parent::__construct("Could not download the plugin artifact from [{$url}]: {$reason}");
    }
}
