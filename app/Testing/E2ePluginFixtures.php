<?php

namespace App\Testing;

use RuntimeException;
use ZipArchive;

/**
 * Deterministic, real (not mocked) plugin JAR bytes for the e2e-only
 * fixture download route (App\Http\Controllers\E2ePluginFixtureController)
 * and catalog source (App\Testing\E2eFixturePluginSource) — see both
 * classes' docblocks for why this exists at all: Playwright drives a
 * REAL running server with no Http::fake() available, so proving "a
 * mismatched download never reaches /minecraft" and "an update failure
 * leaves the installed artifact intact" through real browser clicks
 * needs a real, same-origin, controllable download endpoint instead of
 * the real internet.
 *
 * Every entry's mtime is pinned (ZipArchive::setMtimeName()) so the
 * SAME version string always produces byte-IDENTICAL output across
 * separate PHP processes/requests — confirmed empirically that
 * ZipArchive::addFromString() otherwise embeds a wall-clock-derived
 * timestamp that changes between invocations, which would otherwise
 * make the catalog's declared sha256 (computed in one request) drift
 * from the download route's served bytes (computed in a later,
 * separate request).
 */
final class E2ePluginFixtures
{
    public const PLUGIN_NAME = 'E2eFixturePlugin';

    public const GOOD_VERSION = '1.0.0';

    public const LATEST_VERSION = '1.1.0';

    /**
     * A deliberately WRONG sha256 for the "latest" (1.1.0) release — real,
     * valid jar bytes ARE served at its download URL (see
     * updateJarBytes()), but the catalog declares a hash that does not
     * match them, so App\Plugins\PluginDownloader's real checksum gate
     * genuinely fires against real downloaded bytes, not a simulated
     * failure.
     */
    public const LATEST_DECLARED_SHA256 = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    public static function goodJarBytes(): string
    {
        return self::buildJar(self::GOOD_VERSION);
    }

    public static function goodSha256(): string
    {
        return hash('sha256', self::goodJarBytes());
    }

    public static function updateJarBytes(): string
    {
        return self::buildJar(self::LATEST_VERSION);
    }

    public static function jarBytesFor(string $version): string
    {
        return match ($version) {
            self::GOOD_VERSION => self::goodJarBytes(),
            self::LATEST_VERSION => self::updateJarBytes(),
            default => throw new RuntimeException("No e2e fixture jar for version [{$version}]."),
        };
    }

    private static function buildJar(string $version): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ck-e2e-fixture-').'.jar';
        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not build the e2e fixture jar.');
        }

        $entry = 'paper-plugin.yml';
        $zip->addFromString($entry, 'name: '.self::PLUGIN_NAME."\nversion: '{$version}'\n");
        // Fixed epoch — see class docblock for why this matters.
        $zip->setMtimeName($entry, 1_735_689_600);
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        if ($bytes === false) {
            throw new RuntimeException('Could not read back the e2e fixture jar.');
        }

        return $bytes;
    }
}
