<?php

namespace App\Plugins;

use App\Catalog\Data\PluginRelease;
use App\Plugins\Concerns\QuarantinesArtifacts;
use App\Plugins\Exceptions\PluginArtifactTooLarge;
use App\Plugins\Exceptions\PluginDownloadFailed;
use App\Plugins\Exceptions\PluginReleaseMissingArtifact;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Task 15's Step-1 integrity gate: streams a catalog release's artifact
 * from its download URL into {data_root}/quarantine/{token}, computing
 * SHA-256 DURING streaming (never buffering the whole artifact in memory
 * — see App\Plugins\Concerns\QuarantinesArtifacts), capped at a
 * configurable 100 MiB (`craftkeeper.plugins.max_artifact_bytes`). A
 * computed SHA-256 that does not exactly match the release's published
 * `sha256` throws App\Plugins\Exceptions\PluginChecksumMismatch and the
 * bytes are deleted right there in quarantine — this class has no
 * knowledge of `/minecraft` at all (see QuarantinesArtifacts' docblock),
 * so there is no code path by which a mismatched artifact could ever
 * reach it.
 *
 * Uses Laravel's `Http` facade with `stream: true` so a REAL request
 * against a real server streams its response body from the socket rather
 * than Guzzle buffering the whole thing first; `Http::fake()` (every
 * test in this application) still yields a fully-formed PSR-7 response
 * whose body stream this reads through the identical chunked
 * read()/eof() loop — the SAME code path is exercised whether the
 * response came from a live network call or a fake, which is exactly
 * why `Http::fake()` is sufficient to prove the checksum gate without a
 * real download ever happening (see the brief's own Step-1 test).
 */
final class PluginDownloader
{
    use QuarantinesArtifacts;

    public function download(PluginRelease $release): QuarantinedArtifact
    {
        if ($release->downloadUrl === null || $release->sha256 === null) {
            throw new PluginReleaseMissingArtifact($release->id->identityKey());
        }

        $maxBytes = (int) config('craftkeeper.plugins.max_artifact_bytes');
        [$token, $dir, $tempPath] = $this->beginQuarantine();

        try {
            $response = Http::withOptions(['stream' => true])
                ->connectTimeout((int) config('craftkeeper.plugins.download_connect_timeout_seconds'))
                ->timeout((int) config('craftkeeper.plugins.download_timeout_seconds'))
                ->get($release->downloadUrl);
        } catch (ConnectionException $e) {
            $this->abortQuarantine($dir, $tempPath);

            throw new PluginDownloadFailed($release->downloadUrl, $e->getMessage());
        } catch (Throwable $e) {
            $this->abortQuarantine($dir, $tempPath);

            throw new PluginDownloadFailed($release->downloadUrl, $e->getMessage());
        }

        if ($response->failed()) {
            $this->abortQuarantine($dir, $tempPath);

            throw new PluginDownloadFailed($release->downloadUrl, "HTTP {$response->status()}");
        }

        // Defense #1 (declared size) — see App\Plugins\JarInspector's
        // docblock for the two-defense pattern this mirrors: refused
        // before a single body byte is read whenever the server is
        // honest enough to declare an oversized Content-Length.
        $declared = $response->header('Content-Length');

        if ($declared !== '' && (int) $declared > $maxBytes) {
            $this->abortQuarantine($dir, $tempPath);

            throw PluginArtifactTooLarge::declared((int) $declared, $maxBytes);
        }

        $stream = $response->toPsrResponse()->getBody();

        // Defense #2 (actual bytes) happens inside streamIntoQuarantine():
        // the running total is checked on every chunk actually read,
        // independent of whatever Content-Length claimed (or omitted).
        [$sha256, $bytesWritten] = $this->streamIntoQuarantine($stream, $dir, $tempPath, $maxBytes);

        return $this->finalizeQuarantine($token, $dir, $tempPath, $sha256, $bytesWritten, $release->sha256);
    }
}
