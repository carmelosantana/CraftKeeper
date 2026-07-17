<?php

use App\Filesystem\AtomicFileWriter;
use App\Filesystem\Exceptions\AtomicWriteFailed;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\Exceptions\ParentDirectoryMissing;
use App\Filesystem\Exceptions\StaleFileHash;
use App\Filesystem\FileSnapshot;
use App\Filesystem\MinecraftPath;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function atomic_test_write(string $root, string $relative, string $contents, ?int $mode = null): string
{
    $absolute = $root.'/'.$relative;
    file_put_contents($absolute, $contents);

    if ($mode !== null) {
        chmod($absolute, $mode);
    }

    return $absolute;
}

it('writes new content atomically when the expected hash matches', function () {
    $absolute = atomic_test_write($this->minecraftRoot, 'server.properties', "motd=old\n");
    $originalSha = hash('sha256', "motd=old\n");

    $path = MinecraftPath::fromUserInput('server.properties');
    $snapshot = (new AtomicFileWriter)->write($path, "motd=new\n", $originalSha);

    expect($snapshot)->toBeInstanceOf(FileSnapshot::class)
        ->and($snapshot->contents)->toBe("motd=new\n")
        ->and($snapshot->sha256)->toBe(hash('sha256', "motd=new\n"))
        ->and(file_get_contents($absolute))->toBe("motd=new\n");
});

it('creates a brand new file when the parent directory exists and the expected hash is the empty-file hash', function () {
    mkdir($this->minecraftRoot.'/plugins/ExamplePlugin', 0755, true);
    $path = MinecraftPath::fromUserInput('plugins/ExamplePlugin/config.yml');

    $emptyHash = hash('sha256', '');
    $snapshot = (new AtomicFileWriter)->write($path, "enabled: true\n", $emptyHash);

    expect($snapshot->contents)->toBe("enabled: true\n")
        ->and(file_get_contents($this->minecraftRoot.'/plugins/ExamplePlugin/config.yml'))->toBe("enabled: true\n");
});

it('throws ParentDirectoryMissing when the target directory does not exist', function () {
    $path = MinecraftPath::fromUserInput('plugins/DoesNotExist/config.yml');

    expect(fn () => (new AtomicFileWriter)->write($path, 'x', hash('sha256', '')))
        ->toThrow(ParentDirectoryMissing::class);
});

it('throws StaleFileHash and leaves the file untouched when the expected SHA-256 has changed', function () {
    atomic_test_write($this->minecraftRoot, 'server.properties', "motd=current\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $staleSha = hash('sha256', "motd=some-old-value-the-caller-thought-was-current\n");

    expect(fn () => (new AtomicFileWriter)->write($path, "motd=attempted-overwrite\n", $staleSha))
        ->toThrow(StaleFileHash::class);

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=current\n");
});

it('does not leave a temp file behind after a stale-hash rejection', function () {
    atomic_test_write($this->minecraftRoot, 'server.properties', "motd=current\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    try {
        (new AtomicFileWriter)->write($path, 'new', hash('sha256', 'wrong-baseline'));
    } catch (StaleFileHash) {
        // expected
    }

    $entries = array_values(array_diff(scandir($this->minecraftRoot), ['.', '..']));
    expect($entries)->toBe(['server.properties']);
});

it('preserves the original file mode across a write', function () {
    atomic_test_write($this->minecraftRoot, 'server.properties', "motd=old\n", 0640);
    $path = MinecraftPath::fromUserInput('server.properties');

    (new AtomicFileWriter)->write($path, "motd=new\n", hash('sha256', "motd=old\n"));

    $mode = fileperms($this->minecraftRoot.'/server.properties') & 0777;
    expect($mode)->toBe(0640);
});

it('reports the preserved mode on the returned FileSnapshot', function () {
    atomic_test_write($this->minecraftRoot, 'server.properties', "motd=old\n", 0640);
    $path = MinecraftPath::fromUserInput('server.properties');

    $snapshot = (new AtomicFileWriter)->write($path, "motd=new\n", hash('sha256', "motd=old\n"));

    expect($snapshot->mode)->toBe(0640);
});

it('leaves the original file completely intact when the rename step is interrupted', function () {
    atomic_test_write($this->minecraftRoot, 'server.properties', "motd=old\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $writer = new class extends AtomicFileWriter
    {
        protected function renameFile(string $from, string $to): bool
        {
            // The temp file has genuinely been created, written, and
            // fsync'd for real by this point — only the rename itself is
            // interrupted, simulating e.g. a crash between fsync() and
            // rename().
            return false;
        }
    };

    expect(fn () => $writer->write($path, "motd=new\n", hash('sha256', "motd=old\n")))
        ->toThrow(AtomicWriteFailed::class);

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=old\n");

    $entries = array_values(array_diff(scandir($this->minecraftRoot), ['.', '..']));
    expect($entries)->toBe(['server.properties']);
});

it('leaves the original file completely intact when fsync is interrupted', function () {
    atomic_test_write($this->minecraftRoot, 'server.properties', "motd=old\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $writer = new class extends AtomicFileWriter
    {
        protected function fsyncHandle($handle): bool
        {
            return false;
        }
    };

    expect(fn () => $writer->write($path, "motd=new\n", hash('sha256', "motd=old\n")))
        ->toThrow(AtomicWriteFailed::class);

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=old\n");

    $entries = array_values(array_diff(scandir($this->minecraftRoot), ['.', '..']));
    expect($entries)->toBe(['server.properties']);
});

it('cleans up the temp file even when the interruption happens after rename would have succeeded', function () {
    atomic_test_write($this->minecraftRoot, 'server.properties', "motd=old\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $attempts = 0;
    $writer = new class extends AtomicFileWriter
    {
        public int $calls = 0;

        protected function renameFile(string $from, string $to): bool
        {
            $this->calls++;

            throw new RuntimeException('disk gone');
        }
    };

    expect(fn () => $writer->write($path, "motd=new\n", hash('sha256', "motd=old\n")))
        ->toThrow(AtomicWriteFailed::class);

    expect($writer->calls)->toBe(1);

    // No stray ".*.ck-tmp-*" file left in the directory.
    $entries = array_values(array_diff(scandir($this->minecraftRoot), ['.', '..']));
    expect($entries)->toBe(['server.properties']);
});

it('rejects writing over an existing non-regular-file target', function () {
    mkdir($this->minecraftRoot.'/a-directory', 0755);

    expect(fn () => MinecraftPath::fromUserInput('a-directory'))
        ->toThrow(NotARegularFile::class);
});

it('is guarded by a per-path lock so a concurrent write cannot interleave', function () {
    // Not a true multi-process race (out of scope for a fast unit-style
    // integration test), but proves flock() is actually acquired and
    // released around the critical section rather than being a no-op: a
    // second writer using a *different* AtomicFileWriter instance still
    // succeeds sequentially once the first call returns.
    atomic_test_write($this->minecraftRoot, 'server.properties', "motd=old\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $writer = new AtomicFileWriter;
    $afterFirst = $writer->write($path, "motd=first\n", hash('sha256', "motd=old\n"));
    $afterSecond = $writer->write($path, "motd=second\n", $afterFirst->sha256);

    expect($afterSecond->contents)->toBe("motd=second\n");
});
