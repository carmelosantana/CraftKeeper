<?php

namespace App\Plugins\Concerns;

use App\Plugins\Exceptions\PluginArtifactTooLarge;
use App\Plugins\Exceptions\PluginChecksumMismatch;
use App\Plugins\Exceptions\PluginDownloadFailed;
use App\Plugins\QuarantinedArtifact;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;

/**
 * The shared "stream bytes into a fresh quarantine directory, capped and
 * hashed as they arrive, then verify-or-discard" primitive behind both
 * App\Plugins\PluginDownloader (a remote HTTP source) and
 * App\Plugins\PluginUploadService (a local uploaded file) — Task 15's
 * ambiguity resolution #1/#2. Neither concrete class duplicates this
 * logic; only HOW bytes are produced (an HTTP response stream vs. an
 * uploaded file's stream) differs between them.
 *
 * Every quarantine directory lives at {data_root}/quarantine/{token},
 * NEVER under the Minecraft root — this trait has no knowledge of
 * App\Filesystem\MinecraftPath at all, which is precisely what makes it
 * structurally impossible for it to write toward `/minecraft`: there is
 * no code path here that could even resolve such a destination. The
 * bytes only ever move toward `/minecraft/plugins` later, and only via
 * App\Operations\Handlers\PluginOperationHandler after a human has
 * approved a proposed operation (Task 5's gate).
 */
trait QuarantinesArtifacts
{
    private const CHUNK_BYTES = 65536;

    /**
     * @return array{0: string, 1: string, 2: string} [token, directory, temp file path]
     */
    private function beginQuarantine(): array
    {
        $token = (string) Str::uuid();
        $dir = $this->quarantineRoot().'/'.$token;
        File::ensureDirectoryExists($dir, 0700);

        return [$token, $dir, $dir.'/.artifact.ck-tmp'];
    }

    /**
     * Copies every byte from $source (anything supporting fread()/feof()
     * — a PSR-7 stream body or a plain PHP stream resource opened over an
     * uploaded file both satisfy this) into $tempPath, computing a
     * running SHA-256 and byte count AS EACH CHUNK ARRIVES — never
     * buffering the whole artifact in memory first — and aborting the
     * instant the running total exceeds $maxBytes. On ANY failure
     * (oversize, a write error, an exception from $source itself) the
     * temp file and its directory are deleted before the exception
     * propagates.
     *
     * @param  StreamInterface  $source  Either a real PSR-7 HTTP response body
     *                                   stream (App\Plugins\PluginDownloader) or a
     *                                   plain PHP stream resource wrapped via
     *                                   GuzzleHttp\Psr7\Utils::streamFor()
     *                                   (App\Plugins\PluginUploadService) —
     *                                   unifying on one interface, rather than a
     *                                   resource|StreamInterface union, is what
     *                                   lets this method be written once.
     * @return array{0: string, 1: int} [sha256, bytesWritten]
     */
    private function streamIntoQuarantine(StreamInterface $source, string $dir, string $tempPath, int $maxBytes): array
    {
        $handle = @fopen($tempPath, 'xb');

        if ($handle === false) {
            $this->abortQuarantine($dir, $tempPath);

            throw new PluginDownloadFailed('quarantine', 'Could not create the quarantine temp file.');
        }

        $hashContext = hash_init('sha256');
        $bytesWritten = 0;
        $succeeded = false;

        try {
            while (! $source->eof()) {
                $chunk = $source->read(self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                $bytesWritten += strlen($chunk);

                if ($bytesWritten > $maxBytes) {
                    throw PluginArtifactTooLarge::actual($bytesWritten, $maxBytes);
                }

                hash_update($hashContext, $chunk);

                if (fwrite($handle, $chunk) === false) {
                    throw new PluginDownloadFailed('quarantine', 'Could not write to the quarantine temp file.');
                }
            }

            $succeeded = true;
        } finally {
            fclose($handle);

            if (! $succeeded) {
                $this->abortQuarantine($dir, $tempPath);
            }
        }

        return [hash_final($hashContext), $bytesWritten];
    }

    /**
     * Verifies (when $expectedSha256 is given — always true for a
     * download, never for a manual upload, which has nothing else to
     * compare against) and, only on success, atomically renames the temp
     * file into its final `artifact.jar` name within the SAME quarantine
     * directory — a same-directory rename, safe regardless of the
     * filesystem quarantine itself sits on. A mismatch deletes the temp
     * file and throws PluginChecksumMismatch BEFORE this method — or
     * therefore its caller — ever returns a QuarantinedArtifact pointing
     * at the bad bytes.
     */
    private function finalizeQuarantine(
        string $token,
        string $dir,
        string $tempPath,
        string $sha256,
        int $bytesWritten,
        ?string $expectedSha256,
    ): QuarantinedArtifact {
        if ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), $sha256)) {
            $this->abortQuarantine($dir, $tempPath);

            throw new PluginChecksumMismatch($expectedSha256, $sha256);
        }

        $finalPath = $dir.'/artifact.jar';

        if (! @rename($tempPath, $finalPath)) {
            $this->abortQuarantine($dir, $tempPath);

            throw new PluginDownloadFailed('quarantine', 'Could not finalize the quarantined artifact.');
        }

        return new QuarantinedArtifact($token, $finalPath, $sha256, $bytesWritten);
    }

    private function abortQuarantine(string $dir, string $tempPath): void
    {
        if (is_file($tempPath)) {
            @unlink($tempPath);
        }

        // Only ever removes an EMPTY directory — safe even if something
        // else has already cleaned it up, and never touches a directory
        // that (unexpectedly) still holds other files.
        @rmdir($dir);
    }

    private function quarantineRoot(): string
    {
        return rtrim((string) config('craftkeeper.data_root'), '/').'/quarantine';
    }
}
