<?php

namespace App\Filesystem;

use App\Filesystem\Exceptions\MinecraftRootUnavailable;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;

/**
 * A path proven, at construction time, to canonically resolve inside
 * config('craftkeeper.minecraft_root') — the single security boundary
 * every filesystem read/write in CraftKeeper is confined to. Nothing else
 * in the application is permitted to build an absolute Minecraft-root path
 * by string concatenation; every other Filesystem class only ever receives
 * paths already validated by fromUserInput().
 *
 * Containment algorithm (see fromUserInput()/resolveWithinRoot()):
 *
 * 1. Reject NUL bytes immediately — PHP's own filesystem functions throw a
 *    raw ValueError for an embedded NUL from PHP 8 onward, so this must be
 *    checked before any filesystem call is made at all.
 * 2. Normalize `\` to `/`, reject absolute paths (leading `/`, a Windows
 *    drive letter, or a UNC prefix).
 * 3. Split into segments, dropping `.`/empty segments, and reject any `..`
 *    segment outright — no traversal component may ever appear, even one
 *    that would stay inside the root after collapsing (e.g.
 *    "plugins/../plugins/x.yml" is rejected, not merely normalized).
 * 4. Reject any segment matching a reserved Windows device name
 *    (CON, PRN, AUX, NUL, COM0-9, LPT0-9), case-insensitively, regardless
 *    of position or trailing extension.
 * 5. Resolve the canonical root via realpath(); refuse to proceed if it
 *    does not exist (MinecraftRootUnavailable — a config problem, not an
 *    escape attempt).
 * 6. Walk the cleaned, syntactically-safe relative segments one at a time,
 *    realpath()-resolving the deepest EXISTING ancestor. realpath()
 *    resolves every symlink component it encounters, so a symlink
 *    anywhere along an existing path — not just at the final segment — is
 *    fully dereferenced before the containment check runs. Any segment
 *    that does not yet exist cannot itself be a symlink, so once the walk
 *    reaches the first missing segment the remaining (literal, `..`-free)
 *    segments are appended verbatim to the last resolved ancestor. Every
 *    resolved ancestor (and the fully resolved target, if it exists) must
 *    equal the canonical root or start with "{root}/" — a symlink whose
 *    target resolves outside that prefix fails this check and the whole
 *    resolution throws UnsafeMinecraftPath.
 * 7. If the fully resolved target exists, it must be a regular file
 *    (NotARegularFile otherwise) — directories, devices, FIFOs, and
 *    sockets are never valid Minecraft paths.
 *
 * Residual risk (disclosed, not silently assumed away): this is a
 * check-then-use design, the only kind available to userland PHP on POSIX
 * without O_NOFOLLOW + openat2(RESOLVE_BENEATH). Between this resolution
 * and the moment a file is actually opened, an actor with concurrent write
 * access to the mounted /minecraft volume (outside CraftKeeper's own
 * control — e.g. the Minecraft server process itself, or a plugin) could
 * in principle swap a directory component for a symlink and race the
 * check. AtomicFileWriter narrows this window by re-verifying containment
 * (reverifyContainment()) immediately before it opens anything, but does
 * not eliminate it. Every escape vector reachable through untrusted
 * *input* (the actual attack surface: HTTP path params, REST/MCP tool
 * arguments, AI-suggested paths) is fully closed by this algorithm.
 */
final readonly class MinecraftPath
{
    private const DEVICE_NAMES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM0', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT0', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    private function __construct(
        public string $relativePath,
        public string $absolutePath,
        public bool $exists,
    ) {}

    public static function fromUserInput(string $userInput): self
    {
        if ($userInput === '') {
            throw UnsafeMinecraftPath::forInput($userInput, 'the path is empty');
        }

        if (str_contains($userInput, "\0")) {
            throw UnsafeMinecraftPath::forInput($userInput, 'the path contains a NUL byte');
        }

        $normalized = str_replace('\\', '/', $userInput);

        if (str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:#', $normalized) === 1) {
            throw UnsafeMinecraftPath::forInput($userInput, 'absolute paths are not allowed');
        }

        $segments = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw UnsafeMinecraftPath::forInput($userInput, 'traversal ("..") is not allowed');
            }

            if (self::isReservedDeviceName($segment)) {
                throw UnsafeMinecraftPath::forInput($userInput, "\"{$segment}\" is a reserved device name");
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw UnsafeMinecraftPath::forInput($userInput, 'the path has no real segments');
        }

        $canonicalRoot = self::canonicalRoot();
        $cleanedRelative = implode('/', $segments);

        $absolute = self::resolveWithinRoot($canonicalRoot, $segments, $userInput);
        $exists = file_exists($absolute);

        if ($exists && filetype($absolute) !== 'file') {
            throw new NotARegularFile(new self($cleanedRelative, $absolute, true));
        }

        return new self($cleanedRelative, $absolute, $exists);
    }

    /**
     * Re-runs the containment check against the already-resolved absolute
     * path. Called by AtomicFileWriter/LocalMinecraftFilesystem immediately
     * before touching disk, to narrow (not eliminate — see class docblock)
     * the window between path resolution and actual use.
     */
    public function reverifyContainment(): void
    {
        $canonicalRoot = self::canonicalRoot();

        if (! file_exists($this->absolutePath)) {
            // Nothing exists at the target yet (the common case for a
            // brand-new write) — re-validate every existing ancestor
            // instead of the (not-yet-real) leaf.
            self::resolveWithinRoot($canonicalRoot, explode('/', $this->relativePath), $this->relativePath);

            return;
        }

        $resolved = realpath($this->absolutePath);

        if ($resolved === false || ! self::isContained($canonicalRoot, $resolved)) {
            throw UnsafeMinecraftPath::forInput($this->relativePath, 'the resolved target no longer resolves inside the Minecraft root');
        }

        if (filetype($resolved) !== 'file') {
            throw new NotARegularFile($this);
        }
    }

    private static function canonicalRoot(): string
    {
        $configured = (string) config('craftkeeper.minecraft_root');
        $real = $configured !== '' ? realpath($configured) : false;

        if ($real === false || ! is_dir($real)) {
            throw MinecraftRootUnavailable::make($configured);
        }

        return $real;
    }

    /**
     * @param  list<string>  $segments  cleaned, "."/".."-free path segments
     */
    private static function resolveWithinRoot(string $canonicalRoot, array $segments, string $userInputForErrors): string
    {
        $resolvedAncestor = $canonicalRoot;
        $consumed = 0;

        foreach ($segments as $segment) {
            $probe = $resolvedAncestor.'/'.$segment;
            $resolvedProbe = realpath($probe);

            if ($resolvedProbe === false) {
                // This segment (and therefore everything after it) does not
                // exist yet. It cannot be a symlink, so the remaining
                // segments are safe to append literally.
                break;
            }

            if (! self::isContained($canonicalRoot, $resolvedProbe)) {
                throw UnsafeMinecraftPath::forInput($userInputForErrors, 'the path resolves outside the Minecraft root');
            }

            $resolvedAncestor = $resolvedProbe;
            $consumed++;
        }

        $remaining = array_slice($segments, $consumed);

        return $remaining === []
            ? $resolvedAncestor
            : $resolvedAncestor.'/'.implode('/', $remaining);
    }

    private static function isContained(string $canonicalRoot, string $resolved): bool
    {
        return $resolved === $canonicalRoot || str_starts_with($resolved, $canonicalRoot.'/');
    }

    private static function isReservedDeviceName(string $segment): bool
    {
        $basename = strtoupper((string) preg_replace('/\..*$/', '', $segment));

        return in_array($basename, self::DEVICE_NAMES, true);
    }
}
