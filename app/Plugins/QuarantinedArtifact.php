<?php

namespace App\Plugins;

/**
 * A verified, on-disk artifact sitting at
 * {data_root}/quarantine/{token}/artifact.jar — the result of
 * App\Plugins\PluginDownloader::download() or
 * App\Plugins\PluginUploadService::store() completing successfully.
 * Reaching this point already means: the transfer completed, it never
 * exceeded the configured size cap, and (for a download, where an
 * expected checksum exists) its SHA-256 exactly matches what was
 * expected — see App\Plugins\Concerns\QuarantinesArtifacts.
 *
 * `$token` is the quarantine directory's own name — App\Plugins\
 * PluginLifecycleService moves/renames the whole directory to
 * {data_root}/quarantine/{operation-id} once a real Operation exists for
 * it (see that class's docblock), so this is a staging identity, not
 * necessarily the artifact's final quarantine location.
 */
final readonly class QuarantinedArtifact
{
    public function __construct(
        public string $token,
        public string $absolutePath,
        public string $sha256,
        public int $sizeBytes,
    ) {}
}
