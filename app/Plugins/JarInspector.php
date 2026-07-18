<?php

namespace App\Plugins;

use App\Filesystem\MinecraftPath;
use Symfony\Component\Yaml\Exception\ParseException as SymfonyYamlParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use ZipArchive;

/**
 * Reads a plugin JAR's identity metadata WITHOUT ever extracting an
 * archive entry to disk and WITHOUT ever loading or executing a class
 * from it. A JAR is just a ZIP file; the only bytes this class ever
 * decompresses are the single small metadata entry it goes looking for
 * (`paper-plugin.yml`, falling back to `plugin.yml`), streamed through
 * ZipArchive::getStream() and capped at self::MAX_METADATA_BYTES —
 * NEVER ZipArchive::extractTo() (which writes to disk), and never
 * getFromName()/getFromIndex() with no length bound (which decompresses
 * an entire entry into memory in one call, trusting the archive's own,
 * attacker-controlled declared size). No entry's content is ever
 * `include`d, `eval`'d, or otherwise executed.
 *
 * Two INDEPENDENT defenses close the classic "declared size lies"
 * zip-bomb variant, confirmed empirically against this project's
 * installed ext-zip (see JarInspectorTest and Tests\fixtures\plugins\
 * JarFixtureBuilder::writeLyingSizeEntryTo()'s docblock): DEFLATE is
 * self-terminating, so a hand-crafted archive can declare a small
 * "uncompressed size" in its local/central directory headers while the
 * real compressed bytes still inflate to something far larger —
 * ZipArchive::statIndex()'s `size` field is not proof of anything.
 *
 * 1. The DECLARED size (from statIndex()) is checked BEFORE any
 *    decompression is even attempted — an archive honest enough to
 *    declare a huge size is refused without ever opening a stream for
 *    it, let alone reading from one.
 * 2. The ACTUAL bytes read from the stream are counted on every chunk,
 *    and the read is aborted the instant the running total exceeds the
 *    cap — an archive that LIES about a small declared size while the
 *    real deflate stream keeps producing data is still caught, because
 *    this check depends on nothing the archive itself reports, only on
 *    what has actually, verifiably been read so far.
 *
 * Entry names are never used to build a filesystem path: the only
 * lookups this class performs are exact, literal
 * ZipArchive::locateName() calls against a small, fixed set of
 * well-known root filenames (paper-plugin.yml, plugin.yml,
 * velocity-plugin.json, bungee.yml). A hostile entry named e.g.
 * "../../../etc/passwd" or "/etc/shadow" is therefore never touched —
 * it doesn't match any of those literal names, and even if it did,
 * locateName()'s result is only ever used as an in-memory zip-entry
 * index, never concatenated into a path or passed to any filesystem
 * write call. This class never calls extractTo(), so there is no
 * "write destination" for a traversal-named entry to redirect in the
 * first place.
 *
 * The entry-count cap (self::MAX_ENTRY_COUNT) is checked immediately
 * after opening the archive — via ZipArchive::$numFiles, which libzip
 * already knows from parsing the central directory during open(),
 * costing nothing extra to read — and BEFORE any attempt to locate or
 * read a metadata entry, so a central-directory-flooding archive never
 * reaches that stage at all.
 */
final class JarInspector
{
    /**
     * A metadata file this small is generous for any real plugin.yml/
     * paper-plugin.yml (which are typically well under 4 KiB) while
     * still bounding both the declared-size check and the actual
     * streamed-read count to a fixed, small amount of memory.
     */
    private const MAX_METADATA_BYTES = 256 * 1024;

    /**
     * No real Paper/Bukkit plugin JAR has anywhere near this many
     * archive entries; an archive that does is treated as hostile
     * (central-directory-flooding) rather than inspected further.
     */
    private const MAX_ENTRY_COUNT = 10_000;

    private const READ_CHUNK_BYTES = 8192;

    /** @var list<string> tried in this exact order; the first present wins. */
    private const METADATA_CANDIDATES = ['paper-plugin.yml', 'plugin.yml'];

    /**
     * Root-level descriptors that mean "this archive is a plugin for a
     * different platform entirely" — checked only once neither metadata
     * candidate above is present, purely via locateName() (no read).
     *
     * @var array<string, string> zip entry name => human platform label
     */
    private const FOREIGN_PLATFORM_DESCRIPTORS = [
        'velocity-plugin.json' => 'Velocity',
        'bungee.yml' => 'BungeeCord',
    ];

    public function inspect(MinecraftPath $path): InspectedPlugin
    {
        $path->reverifyContainment();

        return $this->inspectAbsolute($path->absolutePath, $path);
    }

    /**
     * Inspects an archive OUTSIDE the Minecraft root — e.g. a quarantined
     * download/upload sitting at {data_root}/quarantine/{token}/artifact.jar,
     * awaiting an install/update decision (Task 15). Every hostile-safe
     * defense on this class (declared-size-then-actual-bytes, entry-count
     * cap, never extracting/executing anything) applies identically here;
     * only WHERE the archive lives differs.
     *
     * $identity is embedded into the returned InspectedPlugin's `$path`
     * purely as informational scaffolding to satisfy that value object's
     * shape (e.g. App\Plugins\PluginCompatibilityService::evaluate() never
     * reads it) — it is NEVER used to open, read, or otherwise touch any
     * file; only $absoluteQuarantinePath is ever opened. Containment for
     * $absoluteQuarantinePath is the CALLER's responsibility:
     * App\Plugins\PluginDownloader/PluginUploadService only ever construct
     * quarantine paths from a server-generated token (never from
     * attacker-influenced input), so there is no user-controlled path to
     * validate here the way MinecraftPath validates one for inspect().
     */
    public function inspectQuarantined(string $absoluteQuarantinePath, MinecraftPath $identity): InspectedPlugin
    {
        return $this->inspectAbsolute($absoluteQuarantinePath, $identity);
    }

    private function inspectAbsolute(string $absolute, MinecraftPath $path): InspectedPlugin
    {
        $exists = file_exists($absolute) && filetype($absolute) === 'file';

        // Streamed from the raw file on disk (hash_file() reads and
        // hashes in fixed-size chunks internally) — this hashes the JAR
        // exactly as it sits on disk, never anything decompressed from
        // inside it, and works even when the archive itself turns out to
        // be corrupt or hostile below.
        $sha256 = $exists ? (string) hash_file('sha256', $absolute) : str_repeat('0', 64);
        $size = $exists ? (int) (filesize($absolute) ?: 0) : 0;
        $modifiedAt = $exists ? (int) (filemtime($absolute) ?: 0) : 0;

        if (! $exists) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(PluginInspectionIssue::UnreadableArchive, 'The file does not exist on disk.'),
            ]);
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($absolute, ZipArchive::RDONLY);

        if ($openResult !== true) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(PluginInspectionIssue::UnreadableArchive, "The file is not a readable ZIP/JAR archive (libzip error code {$openResult})."),
            ]);
        }

        try {
            return $this->inspectOpenArchive($path, $zip, $sha256, $size, $modifiedAt);
        } finally {
            $zip->close();
        }
    }

    private function inspectOpenArchive(MinecraftPath $path, ZipArchive $zip, string $sha256, int $size, int $modifiedAt): InspectedPlugin
    {
        if ($zip->numFiles > self::MAX_ENTRY_COUNT) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(
                    PluginInspectionIssue::TooManyEntries,
                    "The archive contains {$zip->numFiles} entries, exceeding the ".self::MAX_ENTRY_COUNT.' entry inspection limit.',
                ),
            ]);
        }

        $located = $this->locateMetadataEntry($zip);

        if ($located === null) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, $this->noMetadataDiagnostics($zip));
        }

        [$source, $index] = $located;

        return $this->readMetadataEntry($path, $zip, $source, $index, $sha256, $size, $modifiedAt);
    }

    /**
     * @return ?array{0: string, 1: int}
     */
    private function locateMetadataEntry(ZipArchive $zip): ?array
    {
        foreach (self::METADATA_CANDIDATES as $candidate) {
            $index = $zip->locateName($candidate);

            if ($index !== false) {
                return [$candidate, $index];
            }
        }

        return null;
    }

    /**
     * @return list<PluginInspectionDiagnostic>
     */
    private function noMetadataDiagnostics(ZipArchive $zip): array
    {
        foreach (self::FOREIGN_PLATFORM_DESCRIPTORS as $descriptor => $label) {
            if ($zip->locateName($descriptor) !== false) {
                return [new PluginInspectionDiagnostic(
                    PluginInspectionIssue::ForeignPlatform,
                    "The archive contains a {$descriptor} descriptor and no paper-plugin.yml/plugin.yml — this looks like a {$label} plugin, not a Paper server plugin.",
                )];
            }
        }

        return [new PluginInspectionDiagnostic(
            PluginInspectionIssue::NoMetadata,
            'The archive contains neither paper-plugin.yml nor plugin.yml.',
        )];
    }

    private function readMetadataEntry(MinecraftPath $path, ZipArchive $zip, string $source, int $index, string $sha256, int $size, int $modifiedAt): InspectedPlugin
    {
        $stat = $zip->statIndex($index);

        if ($stat === false) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(PluginInspectionIssue::UnreadableArchive, "Could not read archive metadata for entry [{$source}]."),
            ]);
        }

        $declaredSize = $stat['size'];

        // Defense #1 — see class docblock: refuse a declared size over
        // the cap WITHOUT ever asking libzip to decompress a single byte
        // of this entry.
        if ($declaredSize > self::MAX_METADATA_BYTES) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(
                    PluginInspectionIssue::MetadataTooLarge,
                    "The [{$source}] entry declares an uncompressed size of {$declaredSize} bytes, exceeding the ".self::MAX_METADATA_BYTES.' byte metadata read limit.',
                ),
            ]);
        }

        $entryName = $stat['name'];
        $read = $this->readCapped($zip, $entryName, $source);

        if ($read instanceof PluginInspectionDiagnostic) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [$read], $source);
        }

        return $this->parseMetadata($path, $source, $read, $sha256, $size, $modifiedAt);
    }

    private function readCapped(ZipArchive $zip, string $entryName, string $source): string|PluginInspectionDiagnostic
    {
        $stream = $zip->getStream($entryName);

        if ($stream === false) {
            return new PluginInspectionDiagnostic(PluginInspectionIssue::UnreadableArchive, "Could not open a read stream for entry [{$source}].");
        }

        $bytes = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::READ_CHUNK_BYTES);

                if ($chunk === false) {
                    break;
                }

                $bytes .= $chunk;

                // Defense #2 — see class docblock: depends only on what
                // has actually been read so far, never on anything the
                // archive itself declared, so a "declared size lies"
                // entry cannot bypass it.
                if (strlen($bytes) > self::MAX_METADATA_BYTES) {
                    return new PluginInspectionDiagnostic(
                        PluginInspectionIssue::MetadataTooLarge,
                        "Reading the [{$source}] entry exceeded the ".self::MAX_METADATA_BYTES.' byte metadata read limit before it finished decompressing.',
                    );
                }
            }
        } finally {
            fclose($stream);
        }

        return $bytes;
    }

    private function parseMetadata(MinecraftPath $path, string $source, string $bytes, string $sha256, int $size, int $modifiedAt): InspectedPlugin
    {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(PluginInspectionIssue::MalformedYaml, "The [{$source}] entry is not valid UTF-8 text."),
            ], $source);
        }

        try {
            $data = Yaml::parse($bytes, Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (SymfonyYamlParseException $e) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(PluginInspectionIssue::MalformedYaml, "Could not parse [{$source}]: {$e->getMessage()}"),
            ], $source);
        } catch (Throwable $e) {
            // Belt-and-suspenders: whatever the underlying parser threw,
            // it never escapes this class as a raw exception.
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(PluginInspectionIssue::MalformedYaml, "Could not parse [{$source}]: {$e->getMessage()}"),
            ], $source);
        }

        if (! is_array($data)) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(PluginInspectionIssue::InvalidMetadataStructure, "The [{$source}] document root must be a mapping."),
            ], $source);
        }

        $name = $this->scalarString($data['name'] ?? null);

        if ($name === null) {
            return $this->diagnosticResult($path, $sha256, $size, $modifiedAt, [
                new PluginInspectionDiagnostic(PluginInspectionIssue::InvalidMetadataStructure, "The [{$source}] entry has no usable \"name\" field."),
            ], $source);
        }

        $dependencies = $this->extractDependencies($data);

        return new InspectedPlugin(
            path: $path,
            name: $name,
            version: $this->scalarString($data['version'] ?? null),
            mainClass: $this->scalarString($data['main'] ?? null),
            apiVersion: $this->scalarString($data['api-version'] ?? null),
            hardDependencies: $dependencies['hard'],
            softDependencies: $dependencies['soft'],
            metadataSource: $source,
            sha256: $sha256,
            sizeBytes: $size,
            modifiedAt: $modifiedAt,
            diagnostics: [],
        );
    }

    /**
     * Supports both the legacy Bukkit/Spigot plugin.yml shape
     * (top-level `depend`/`softdepend` lists of plugin names) and the
     * Paper-style paper-plugin.yml shape (`dependencies.server.<Name>`,
     * each entry a mapping with an optional `required` boolean,
     * defaulting to true) — a plugin.yml is free to use either shape, so
     * both are always checked regardless of which file was found.
     *
     * @param  array<string, mixed>  $data
     * @return array{hard: list<string>, soft: list<string>}
     */
    private function extractDependencies(array $data): array
    {
        $hard = $this->stringList($data['depend'] ?? null);
        $soft = $this->stringList($data['softdepend'] ?? null);

        $dependencies = is_array($data['dependencies'] ?? null) ? $data['dependencies'] : null;
        $serverDependencies = is_array($dependencies['server'] ?? null) ? $dependencies['server'] : null;

        if ($serverDependencies !== null) {
            foreach ($serverDependencies as $depName => $spec) {
                if (! is_string($depName) || $depName === '') {
                    continue;
                }

                $required = true;

                if (is_array($spec) && array_key_exists('required', $spec)) {
                    $required = (bool) $spec['required'];
                }

                if ($required) {
                    $hard[] = $depName;
                } else {
                    $soft[] = $depName;
                }
            }
        }

        return [
            'hard' => array_values(array_unique($hard)),
            'soft' => array_values(array_unique($soft)),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private function scalarString(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    /**
     * @param  list<PluginInspectionDiagnostic>  $diagnostics
     */
    private function diagnosticResult(MinecraftPath $path, string $sha256, int $size, int $modifiedAt, array $diagnostics, ?string $metadataSource = null): InspectedPlugin
    {
        return new InspectedPlugin(
            path: $path,
            name: null,
            version: null,
            mainClass: null,
            apiVersion: null,
            hardDependencies: [],
            softDependencies: [],
            metadataSource: $metadataSource,
            sha256: $sha256,
            sizeBytes: $size,
            modifiedAt: $modifiedAt,
            diagnostics: $diagnostics,
        );
    }
}
