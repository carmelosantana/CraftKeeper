<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Test-only helper: a fresh, disposable "Minecraft root" and "data root"
 * pair per test, so write/atomicity/snapshot tests never touch the
 * git-tracked fixtures under tests/fixtures/minecraft (which
 * MinecraftPathTest reads but must stay pristine for every other test).
 */
final class TempMinecraftRoot
{
    public static function create(string $prefix = 'craftkeeper-test-mc-'): string
    {
        $path = storage_path($prefix.uniqid());
        File::makeDirectory($path, 0755, true, true);

        return realpath($path) ?: $path;
    }

    public static function createDataRoot(string $prefix = 'craftkeeper-test-data-'): string
    {
        $path = storage_path($prefix.uniqid());
        File::makeDirectory($path, 0755, true, true);

        return realpath($path) ?: $path;
    }

    public static function destroy(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }
}
