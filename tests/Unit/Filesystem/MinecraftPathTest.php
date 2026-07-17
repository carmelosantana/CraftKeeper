<?php

use App\Filesystem\Exceptions\MinecraftRootUnavailable;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftPath;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    config(['craftkeeper.minecraft_root' => realpath(base_path('tests/fixtures/minecraft'))]);
});

// The brief's verbatim escape-vector test — do not alter the shape.
it('rejects traversal and escaping symlinks', function (string $path) {
    expect(fn () => MinecraftPath::fromUserInput($path))
        ->toThrow(UnsafeMinecraftPath::class);
})->with(['../etc/passwd', '/etc/passwd', "plugins/\0bad.yml"]);

it('rejects a traversal component even when it would collapse back inside the root', function () {
    // "plugins/../plugins/Geyser-Spigot/config.yml" collapses to a path
    // that IS inside the root, so the pure containment check alone would
    // not catch it — the explicit ".." rejection is what closes this.
    expect(fn () => MinecraftPath::fromUserInput('plugins/../plugins/Geyser-Spigot/config.yml'))
        ->toThrow(UnsafeMinecraftPath::class);
});

it('rejects additional traversal, absolute, and NUL-byte variants', function (string $path) {
    expect(fn () => MinecraftPath::fromUserInput($path))
        ->toThrow(UnsafeMinecraftPath::class);
})->with([
    'plugins/../../etc/passwd',
    './../../etc/passwd',
    'plugins/../../../etc/passwd',
    '..',
    '../',
    '..\\..\\etc\\passwd',
    'C:\\Windows\\system32',
    '\\\\server\\share\\file.yml',
    "server.properties\0.yml",
    '',
]);

it('rejects reserved Windows device names anywhere in the path', function (string $path) {
    expect(fn () => MinecraftPath::fromUserInput($path))
        ->toThrow(UnsafeMinecraftPath::class);
})->with([
    'CON',
    'con.yml',
    'plugins/NUL.yml',
    'COM1/config.yml',
    'plugins/LPT1/config.yml',
]);

it('accepts a symlink inside the fixture root pointing inside it', function () {
    $path = MinecraftPath::fromUserInput('inside-link.yml');

    expect($path->exists)->toBeTrue()
        ->and($path->absolutePath)->toBe(realpath(base_path('tests/fixtures/minecraft/config/paper-global.yml')));
});

it('rejects a symlink inside the fixture root that points outside it', function () {
    expect(fn () => MinecraftPath::fromUserInput('escape-link.yml'))
        ->toThrow(UnsafeMinecraftPath::class);
});

it('rejects a directory symlink that points outside the root before descending into it', function () {
    expect(fn () => MinecraftPath::fromUserInput('escape-dir/secret.txt'))
        ->toThrow(UnsafeMinecraftPath::class);
});

it('resolves a plain, safe relative path that exists', function () {
    $path = MinecraftPath::fromUserInput('server.properties');

    expect($path->relativePath)->toBe('server.properties')
        ->and($path->exists)->toBeTrue()
        ->and($path->absolutePath)->toBe(realpath(base_path('tests/fixtures/minecraft/server.properties')));
});

it('resolves a nested plugin path that exists', function () {
    $path = MinecraftPath::fromUserInput('plugins/Geyser-Spigot/config.yml');

    expect($path->relativePath)->toBe('plugins/Geyser-Spigot/config.yml')
        ->and($path->exists)->toBeTrue();
});

it('resolves a path for a file that does not exist yet, as long as its parent directory is contained', function () {
    $path = MinecraftPath::fromUserInput('plugins/ExamplePlugin/brand-new.yml');

    expect($path->exists)->toBeFalse()
        ->and($path->absolutePath)->toBe(
            realpath(base_path('tests/fixtures/minecraft/plugins/ExamplePlugin')).'/brand-new.yml',
        );
});

it('normalizes backslashes as path separators', function () {
    $path = MinecraftPath::fromUserInput('plugins\\Geyser-Spigot\\config.yml');

    expect($path->relativePath)->toBe('plugins/Geyser-Spigot/config.yml');
});

it('rejects a path resolving to an existing directory', function () {
    expect(fn () => MinecraftPath::fromUserInput('plugins/Geyser-Spigot'))
        ->toThrow(NotARegularFile::class);
});

it('rejects a path resolving to a FIFO', function () {
    $root = TempMinecraftRoot::create();
    $fifoPath = $root.'/a.fifo';
    exec('mkfifo '.escapeshellarg($fifoPath));
    config(['craftkeeper.minecraft_root' => $root]);

    try {
        expect(fn () => MinecraftPath::fromUserInput('a.fifo'))
            ->toThrow(NotARegularFile::class);
    } finally {
        TempMinecraftRoot::destroy($root);
    }
});

it('throws MinecraftRootUnavailable when the configured root does not exist', function () {
    config(['craftkeeper.minecraft_root' => storage_path('craftkeeper-nonexistent-'.uniqid())]);

    expect(fn () => MinecraftPath::fromUserInput('server.properties'))
        ->toThrow(MinecraftRootUnavailable::class);
});

it('reverifyContainment passes for an untouched, still-contained path', function () {
    $path = MinecraftPath::fromUserInput('server.properties');

    $path->reverifyContainment();
})->throwsNoExceptions();

it('reverifyContainment throws once the target has been swapped for an escaping symlink', function () {
    $root = TempMinecraftRoot::create();
    $outside = TempMinecraftRoot::create('craftkeeper-test-outside-');
    file_put_contents($outside.'/secret.txt', 'outside');
    file_put_contents($root.'/config.yml', 'inside');

    config(['craftkeeper.minecraft_root' => $root]);
    $path = MinecraftPath::fromUserInput('config.yml');

    try {
        // Simulate a TOCTOU race: something with write access to the
        // mounted volume swaps the file for a symlink that escapes root
        // after CraftKeeper already resolved a safe MinecraftPath.
        unlink($root.'/config.yml');
        symlink($outside.'/secret.txt', $root.'/config.yml');

        expect(fn () => $path->reverifyContainment())->toThrow(UnsafeMinecraftPath::class);
    } finally {
        TempMinecraftRoot::destroy($root);
        TempMinecraftRoot::destroy($outside);
    }
});
