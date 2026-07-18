<?php

namespace App\Server;

use App\Filesystem\Exceptions\MinecraftRootUnavailable;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftPath;

/**
 * Task 12's "Server detail shows ... version data discovered from
 * logs/JAR metadata." Two independent, best-effort sources are tried, in
 * order — the first that yields a real value wins:
 *
 *   1. A root-level server JAR filename (e.g. "paper-1.21.4-130.jar"),
 *      the same convention every mainstream Minecraft server distribution
 *      (vanilla, Paper, Purpur, Spigot, Fabric, Forge) ships under.
 *   2. A bounded scan of the first lines of logs/latest.log for the
 *      startup banner vanilla/Paper/Purpur/Spigot/Folia print once, near
 *      the top of the log, on every boot ("Starting minecraft server
 *      version ..." / "This server is running Paper version ...").
 *
 * Neither source is guaranteed to exist (a fresh/unbooted server has no
 * log banner yet; a non-standard JAR filename carries no parseable
 * version). When neither yields anything, this returns
 * ServerVersion::unavailable() with a specific reason — never a guessed
 * or fabricated label, matching the "no fabricated zero" principle
 * App\Server\ServerStatusService already applies to player counts.
 *
 * Mirrors App\Config\ConfigDiscoveryService's own `canonicalRoot()`
 * pattern (config('craftkeeper.minecraft_root') + realpath()) rather than
 * depending on it or on MinecraftFilesystem::discover() — a bare
 * server.jar next to server.properties is not itself a "configuration
 * file" that service's inventory is scoped to, and root-level-only,
 * read-only directory listing needs nothing else that class provides.
 */
final class ServerVersionDetector
{
    private const JAR_SCAN_LIMIT = 25;

    private const LOG_SCAN_MAX_BYTES = 65_536; // 64 KiB

    private const LOG_SCAN_MAX_LINES = 500;

    /** @var array<string, string> */
    private const KNOWN_SOFTWARE = [
        'paper' => 'Paper',
        'purpur' => 'Purpur',
        'spigot' => 'Spigot',
        'folia' => 'Folia',
        'fabric' => 'Fabric',
        'forge' => 'Forge',
        'vanilla' => 'Vanilla',
        'server' => 'Vanilla',
    ];

    public function detect(): ServerVersion
    {
        $root = $this->canonicalRoot();

        if ($root === null) {
            return ServerVersion::unavailable('The Minecraft root is unavailable.');
        }

        $fromJar = $this->detectFromJar($root);

        if ($fromJar instanceof ServerVersion) {
            return $fromJar;
        }

        $fromLog = $this->detectFromLog();

        if ($fromLog instanceof ServerVersion) {
            return $fromLog;
        }

        return ServerVersion::unavailable('No server JAR filename or startup log banner was found.');
    }

    private function canonicalRoot(): ?string
    {
        $configured = (string) config('craftkeeper.minecraft_root');
        $real = $configured !== '' ? realpath($configured) : false;

        return $real === false ? null : $real;
    }

    private function detectFromJar(string $root): ?ServerVersion
    {
        $entries = @scandir($root);

        if ($entries === false) {
            return null;
        }

        sort($entries);
        $seen = 0;

        foreach ($entries as $entry) {
            if (! str_ends_with(strtolower($entry), '.jar')) {
                continue;
            }

            if (++$seen > self::JAR_SCAN_LIMIT) {
                break;
            }

            $label = $this->labelFromJarFilename($entry);

            if ($label !== null) {
                return ServerVersion::known($label, 'jar');
            }
        }

        return null;
    }

    private function labelFromJarFilename(string $filename): ?string
    {
        $stem = preg_replace('/\.jar$/i', '', $filename) ?? $filename;

        // Deliberately just the semantic Minecraft version (\d+.\d+[.\d+])
        // — NOT a trailing build-number suffix some distributions append
        // (e.g. "paper-1.21.4-130.jar"'s "-130" is Paper's own build
        // number, not part of the Minecraft version). The log banner
        // below captures a fuller string on purpose, since that IS the
        // software's own self-reported version string, not a filename
        // convention this class is inferring extra structure from.
        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $stem, $versionMatch) !== 1) {
            return null;
        }

        $version = $versionMatch[1];
        $softwareKey = strtolower((string) preg_replace('/[^a-zA-Z].*/', '', $stem));
        $software = self::KNOWN_SOFTWARE[$softwareKey] ?? null;

        return $software !== null ? "{$software} {$version}" : $stem;
    }

    private function detectFromLog(): ?ServerVersion
    {
        try {
            $path = MinecraftPath::fromUserInput(LogTailService::DEFAULT_LOG_PATH);
        } catch (MinecraftRootUnavailable|UnsafeMinecraftPath) {
            return null;
        }

        if (! $path->exists || ! is_readable($path->absolutePath)) {
            return null;
        }

        $handle = @fopen($path->absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $bytesRead = 0;
            $lineNumber = 0;
            $vanillaLabel = null;

            while ($lineNumber < self::LOG_SCAN_MAX_LINES && $bytesRead < self::LOG_SCAN_MAX_BYTES) {
                $line = fgets($handle);

                if ($line === false) {
                    break;
                }

                $bytesRead += strlen($line);
                $lineNumber++;

                // A branded banner (Paper/Purpur/Spigot/Folia) is more
                // specific than the generic vanilla one — both commonly
                // appear in the SAME real Paper/Purpur log (vanilla's own
                // startup line still fires underneath), so a branded match
                // found anywhere in the scanned window wins even if the
                // vanilla line happened to appear first.
                $branded = $this->brandedLabelFromLogLine($line);

                if ($branded !== null) {
                    return ServerVersion::known($branded, 'log');
                }

                if ($vanillaLabel === null) {
                    $vanillaLabel = $this->vanillaLabelFromLogLine($line);
                }
            }

            if ($vanillaLabel !== null) {
                return ServerVersion::known($vanillaLabel, 'log');
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function brandedLabelFromLogLine(string $line): ?string
    {
        if (preg_match('/This server is running (Paper|Purpur|Spigot|Folia) version ([^\s(]+)/i', $line, $matches) === 1) {
            return trim($matches[1].' '.$matches[2]);
        }

        return null;
    }

    private function vanillaLabelFromLogLine(string $line): ?string
    {

        if (preg_match('/Starting minecraft server version ([^\s]+)/i', $line, $matches) === 1) {
            return 'Vanilla '.trim($matches[1]);
        }

        return null;
    }
}
