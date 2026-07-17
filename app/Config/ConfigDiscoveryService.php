<?php

namespace App\Config;

use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftPath;

/**
 * Walks the mounted Minecraft root and builds a bounded inventory of
 * configuration-shaped files, classified purely by path/extension
 * convention — this never opens a file to interpret its format (that is
 * Task 7's job). Backs MinecraftFilesystem::discover().
 *
 * Included: root-level recognized server files (server.properties,
 * ops.json, whitelist.json, ...), files under config/ (Paper), files
 * under plugins/* (including the Geyser/Floodgate conventional paths),
 * and any other file with a supported extension CraftKeeper doesn't yet
 * have a specific convention for (returned as generic/"Discovered").
 *
 * Excluded: logs/, playerdata/, stats/, advancements/, and any world*
 * directory at the Minecraft root (these are recognized only at the root
 * to avoid false-positiving a legitimately named plugin folder, e.g.
 * plugins/WorldEdit or plugins/Stats); hidden directories/files (a
 * leading "."); backup directories/files (a directory literally named
 * "backup"/"backups" at any depth, or any file ending in "~"); files
 * whose content looks binary (a NUL byte in the first 8 KiB — the same
 * heuristic git itself uses); files over 2 MiB; and any file whose
 * extension isn't one of the four supported config formats.
 */
class ConfigDiscoveryService
{
    private const MAX_DEPTH = 10;

    private const MAX_FILES = 1000;

    private const MAX_BYTES = 2 * 1024 * 1024;

    private const BINARY_SNIFF_BYTES = 8000;

    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = ['properties', 'yml', 'yaml', 'json', 'toml'];

    /** @var list<string> */
    private const ROOT_ONLY_IGNORED_SEGMENTS = ['logs', 'playerdata', 'stats', 'advancements'];

    /** @var list<string> */
    private const ANY_DEPTH_IGNORED_SEGMENTS = ['backup', 'backups'];

    /** @var list<string> */
    private const RECOGNIZED_ROOT_FILES = [
        'server.properties', 'ops.json', 'whitelist.json',
        'banned-players.json', 'banned-ips.json', 'usercache.json',
    ];

    /** @var list<string> */
    private const RECOGNIZED_PAPER_FILES = [
        'paper-global.yml', 'paper-world-defaults.yml', 'bukkit.yml',
        'spigot.yml', 'commands.yml', 'permissions.yml', 'help.yml',
    ];

    /**
     * @return list<DiscoveredFile>
     */
    public function discover(): array
    {
        $root = $this->canonicalRoot();

        if ($root === null) {
            return [];
        }

        $results = [];
        $this->walk($root, '', $results, 0);

        usort($results, fn (DiscoveredFile $a, DiscoveredFile $b): int => $a->path->relativePath <=> $b->path->relativePath);

        return $results;
    }

    private function canonicalRoot(): ?string
    {
        $configured = (string) config('craftkeeper.minecraft_root');
        $real = $configured !== '' ? realpath($configured) : false;

        return $real === false ? null : $real;
    }

    /**
     * @param  list<DiscoveredFile>  $results
     */
    private function walk(string $canonicalRoot, string $relativeDir, array &$results, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || count($results) >= self::MAX_FILES) {
            return;
        }

        $absoluteDir = $relativeDir === '' ? $canonicalRoot : $canonicalRoot.'/'.$relativeDir;
        $entries = @scandir($absoluteDir);

        if ($entries === false) {
            return;
        }

        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (count($results) >= self::MAX_FILES) {
                return;
            }

            if ($this->isIgnoredSegment($entry, $depth)) {
                continue;
            }

            $relativePath = $relativeDir === '' ? $entry : $relativeDir.'/'.$entry;
            $absoluteEntry = $absoluteDir.'/'.$entry;

            // realpath() dereferences every symlink component. Anything
            // that doesn't resolve, or resolves outside the canonical
            // root, is skipped outright — discovery never descends into
            // or reports an escaping symlink.
            $resolved = realpath($absoluteEntry);

            if ($resolved === false) {
                continue;
            }

            if ($resolved !== $canonicalRoot && ! str_starts_with($resolved, $canonicalRoot.'/')) {
                continue;
            }

            $type = filetype($resolved);

            if ($type === 'dir') {
                $this->walk($canonicalRoot, $relativePath, $results, $depth + 1);

                continue;
            }

            if ($type !== 'file') {
                continue;
            }

            $this->considerFile($relativePath, $resolved, $results);
        }
    }

    /**
     * @param  list<DiscoveredFile>  $results
     */
    private function considerFile(string $relativePath, string $absolutePath, array &$results): void
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return;
        }

        $size = filesize($absolutePath);

        if ($size === false || $size > self::MAX_BYTES) {
            return;
        }

        if ($this->looksBinary($absolutePath)) {
            return;
        }

        try {
            $path = MinecraftPath::fromUserInput($relativePath);
        } catch (UnsafeMinecraftPath) {
            // Defensive only — the walk above already proved this entry
            // resolves inside the root, so this should never trigger.
            return;
        }

        [$category, $provenance, $recognized] = $this->classify($relativePath);

        $results[] = new DiscoveredFile($path, $category, $provenance, $recognized, $extension, $size);
    }

    /**
     * @return array{0: DiscoveredFileCategory, 1: string, 2: bool}
     */
    private function classify(string $relativePath): array
    {
        $segments = explode('/', $relativePath);
        $filename = (string) end($segments);

        if (count($segments) === 1) {
            if (in_array($filename, self::RECOGNIZED_ROOT_FILES, true)) {
                return [DiscoveredFileCategory::Server, 'Built in', true];
            }

            return [DiscoveredFileCategory::Other, 'Discovered', false];
        }

        if ($segments[0] === 'config') {
            $recognized = in_array($filename, self::RECOGNIZED_PAPER_FILES, true);

            return [DiscoveredFileCategory::Paper, $recognized ? 'Built in' : 'Discovered', $recognized];
        }

        if ($segments[0] === 'plugins' && count($segments) >= 2) {
            $pluginDir = strtolower($segments[1]);

            if (str_starts_with($pluginDir, 'geyser')) {
                return [DiscoveredFileCategory::Geyser, 'Plugin', true];
            }

            if (str_starts_with($pluginDir, 'floodgate')) {
                return [DiscoveredFileCategory::Floodgate, 'Plugin', true];
            }

            return [DiscoveredFileCategory::Plugin, 'Plugin', false];
        }

        return [DiscoveredFileCategory::Other, 'Discovered', false];
    }

    private function isIgnoredSegment(string $segment, int $depth): bool
    {
        if (str_starts_with($segment, '.')) {
            return true;
        }

        if (str_ends_with($segment, '~')) {
            return true;
        }

        $lower = strtolower($segment);

        if (in_array($lower, self::ANY_DEPTH_IGNORED_SEGMENTS, true)) {
            return true;
        }

        if ($depth === 0) {
            if (in_array($lower, self::ROOT_ONLY_IGNORED_SEGMENTS, true)) {
                return true;
            }

            if (str_starts_with($lower, 'world')) {
                return true;
            }
        }

        return false;
    }

    private function looksBinary(string $absolutePath): bool
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return true;
        }

        $chunk = fread($handle, self::BINARY_SNIFF_BYTES);
        fclose($handle);

        return $chunk === false || str_contains($chunk, "\0");
    }
}
