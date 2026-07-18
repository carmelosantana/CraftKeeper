<?php

namespace App\Plugins;

use App\Plugins\Concerns\QuarantinesArtifacts;
use App\Plugins\Exceptions\PluginArtifactTooLarge;
use App\Plugins\Exceptions\PluginDownloadFailed;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\UploadedFile;

/**
 * The manual-upload counterpart to App\Plugins\PluginDownloader — streams
 * an operator-uploaded JAR into quarantine, capped and hashed exactly the
 * same way (see App\Plugins\Concerns\QuarantinesArtifacts), the only
 * difference being the source of bytes (an already-on-disk uploaded temp
 * file rather than an HTTP response) and that there is no
 * externally-published checksum to verify against — the artifact's own
 * computed SHA-256 simply becomes its identity.
 */
final class PluginUploadService
{
    use QuarantinesArtifacts;

    public function store(UploadedFile $file): QuarantinedArtifact
    {
        $maxBytes = (int) config('craftkeeper.plugins.max_artifact_bytes');

        // Defense #1 (declared size) — the OS-reported size of the
        // already-fully-received uploaded temp file, checked before a
        // single byte is copied into quarantine.
        $declaredSize = $file->getSize();

        if ($declaredSize !== false && $declaredSize > $maxBytes) {
            throw PluginArtifactTooLarge::declared($declaredSize, $maxBytes);
        }

        [$token, $dir, $tempPath] = $this->beginQuarantine();

        $realPath = $file->getRealPath();
        $handle = $realPath !== false ? @fopen($realPath, 'rb') : false;

        if ($handle === false) {
            $this->abortQuarantine($dir, $tempPath);

            throw new PluginDownloadFailed('upload', 'Could not read the uploaded file.');
        }

        // Wrapped into a PSR-7 stream so this goes through the IDENTICAL
        // read()/eof() loop App\Plugins\PluginDownloader's real HTTP
        // response body uses — see QuarantinesArtifacts::
        // streamIntoQuarantine()'s docblock for why unifying on one
        // interface (rather than a resource|StreamInterface union) lets
        // that method be written once.
        $source = Utils::streamFor($handle);

        try {
            // Defense #2 (actual bytes) happens inside
            // streamIntoQuarantine(): the running total is checked on
            // every chunk actually copied.
            [$sha256, $bytesWritten] = $this->streamIntoQuarantine($source, $dir, $tempPath, $maxBytes);
        } finally {
            $source->close();
        }

        return $this->finalizeQuarantine($token, $dir, $tempPath, $sha256, $bytesWritten, expectedSha256: null);
    }
}
