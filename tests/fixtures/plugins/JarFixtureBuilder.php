<?php

namespace Tests\fixtures\plugins;

use RuntimeException;
use ZipArchive;

/**
 * Builds real, on-disk JAR (ZIP) fixtures for App\Plugins\JarInspectorTest
 * and PluginInventoryServiceTest — programmatically, via ZipArchive,
 * rather than committing opaque binary .jar blobs to the repository. This
 * is what makes every hostile case (ZIP bomb, traversal entry, huge/lying
 * metadata, malformed YAML, missing metadata, plugin.yml fallback,
 * duplicate names) reproducible from source.
 *
 * Immutable/fluent: every with*() call returns a new instance, so a
 * builder can be reused as a base for several variations in one test file
 * without the entries list bleeding between them.
 */
final class JarFixtureBuilder
{
    /** @var array<string, string> zip entry name => raw content */
    private array $entries = [];

    public static function make(): self
    {
        return new self;
    }

    public function withEntry(string $name, string $content): self
    {
        $clone = clone $this;
        $clone->entries[$name] = $content;

        return $clone;
    }

    public function withPaperPluginYaml(string $yaml): self
    {
        return $this->withEntry('paper-plugin.yml', $yaml);
    }

    public function withPluginYaml(string $yaml): self
    {
        return $this->withEntry('plugin.yml', $yaml);
    }

    /**
     * Pads the archive with $count trivial, distinctly-named entries —
     * used to exercise the 10,000 entry inspection cap. Deliberately
     * tiny content per entry so building even 10,001+ of them stays fast.
     */
    public function withManyEntries(int $count, string $prefix = 'padding-'): self
    {
        $clone = clone $this;

        for ($i = 0; $i < $count; $i++) {
            $clone->entries[$prefix.$i.'.bin'] = 'x';
        }

        return $clone;
    }

    public function writeTo(string $absolutePath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create fixture archive at [{$absolutePath}].");
        }

        foreach ($this->entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
    }

    /**
     * Writes a single-entry archive whose LOCAL and CENTRAL DIRECTORY
     * file headers both declare an "uncompressed size" of $lieSize bytes
     * — far smaller than $realContent actually is — while the entry's
     * real compressed (DEFLATE) data is left untouched, still decoding to
     * the full $realContent when actually decompressed.
     *
     * This is the "declared size lies" zip-bomb variant: DEFLATE is
     * self-terminating (an end-of-block marker inside the compressed
     * bitstream itself, independent of any length field), so a
     * decompressor that simply reads until the deflate stream ends —
     * which is exactly what PHP's ZipArchive::getStream() + fread() does
     * — produces every real decompressed byte regardless of what the
     * archive's own metadata claims. Empirically confirmed against this
     * project's installed ext-zip: ZipArchive::statIndex()'s `size`
     * faithfully reports the PATCHED lie, while reading the entry's
     * stream yields the real, larger byte count. This is exactly why
     * App\Plugins\JarInspector enforces a SECOND cap on bytes actually
     * read from the stream, never trusting statIndex()'s `size` alone.
     *
     * Local file header layout (fixed 30-byte prefix, per the ZIP
     * format): signature(4) version(2) flags(2) method(2) time(2)
     * date(2) crc32(4) compressedSize(4) uncompressedSize(4) @ offset 22
     * nameLength(2) extraLength(2).
     *
     * Central directory file header layout (fixed 46-byte prefix):
     * signature(4) ... crc32(4)@16 compressedSize(4)@20
     * uncompressedSize(4) @ offset 24 ...
     */
    public function writeLyingSizeEntryTo(string $absolutePath, string $entryName, string $realContent, int $lieSize): void
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create fixture archive at [{$absolutePath}].");
        }

        $zip->addFromString($entryName, $realContent);
        $zip->close();

        $bytes = file_get_contents($absolutePath);

        if ($bytes === false) {
            throw new RuntimeException("Could not read back fixture archive at [{$absolutePath}].");
        }

        $lie = pack('V', $lieSize);

        $localHeaderOffset = strpos($bytes, "PK\x03\x04");

        if ($localHeaderOffset === false) {
            throw new RuntimeException('Local file header signature not found in freshly written archive.');
        }

        $bytes = substr_replace($bytes, $lie, $localHeaderOffset + 22, 4);

        $centralHeaderOffset = strpos($bytes, "PK\x01\x02");

        if ($centralHeaderOffset === false) {
            throw new RuntimeException('Central directory header signature not found in freshly written archive.');
        }

        $bytes = substr_replace($bytes, $lie, $centralHeaderOffset + 24, 4);

        file_put_contents($absolutePath, $bytes);
    }
}
