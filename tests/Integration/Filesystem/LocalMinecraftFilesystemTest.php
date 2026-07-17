<?php

use App\Config\ConfigDiscoveryService;
use App\Config\DiscoveredFile;
use App\Filesystem\AtomicFileWriter;
use App\Filesystem\Exceptions\MinecraftFileNotFound;
use App\Filesystem\Exceptions\NotARegularFile;
use App\Filesystem\FileSnapshot;
use App\Filesystem\LocalMinecraftFilesystem;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Filesystem\SnapshotReference;
use App\Filesystem\SnapshotStore;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);

    $this->filesystem = new LocalMinecraftFilesystem(
        new ConfigDiscoveryService,
        new AtomicFileWriter,
        new SnapshotStore,
    );
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

it('implements the MinecraftFilesystem contract', function () {
    expect($this->filesystem)->toBeInstanceOf(MinecraftFilesystem::class);
});

it('is resolved from the container as the MinecraftFilesystem binding', function () {
    config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);

    expect(app(MinecraftFilesystem::class))->toBeInstanceOf(LocalMinecraftFilesystem::class);
});

it('reads a file into an immutable FileSnapshot', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $snapshot = $this->filesystem->read($path);

    expect($snapshot)->toBeInstanceOf(FileSnapshot::class)
        ->and($snapshot->contents)->toBe("motd=hi\n")
        ->and($snapshot->sha256)->toBe(hash('sha256', "motd=hi\n"));
});

it('throws MinecraftFileNotFound reading a path that does not exist', function () {
    $path = MinecraftPath::fromUserInput('does-not-exist.yml');

    expect(fn () => $this->filesystem->read($path))->toThrow(MinecraftFileNotFound::class);
});

it('throws NotARegularFile reading a directory', function () {
    mkdir($this->minecraftRoot.'/plugins', 0755);

    // MinecraftPath itself already refuses to represent a directory as a
    // readable/writable target, so the rejection happens at construction
    // time — before read() would even be reachable.
    expect(fn () => $this->filesystem->read(MinecraftPath::fromUserInput('plugins')))
        ->toThrow(NotARegularFile::class);
});

it('writes atomically through the interface and returns a FileSnapshot', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=old\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $snapshot = $this->filesystem->writeAtomically($path, "motd=new\n", hash('sha256', "motd=old\n"));

    expect($snapshot->contents)->toBe("motd=new\n")
        ->and(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=new\n");
});

it('copies to a snapshot through the interface', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $reference = $this->filesystem->copyToSnapshot($path, 'op-abc');

    expect($reference)->toBeInstanceOf(SnapshotReference::class)
        ->and(file_get_contents($reference->snapshotPath))->toBe("motd=hi\n");
});

it('discovers files through the interface', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");

    $discovered = $this->filesystem->discover();

    expect($discovered)->toBeArray();
    expect($discovered[0])->toBeInstanceOf(DiscoveredFile::class);
});

it('full lifecycle: snapshot, write, and verify the snapshot still reflects the pre-write content', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=before\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    $reference = $this->filesystem->copyToSnapshot($path, 'op-lifecycle');
    $this->filesystem->writeAtomically($path, "motd=after\n", hash('sha256', "motd=before\n"));

    expect(file_get_contents($this->minecraftRoot.'/server.properties'))->toBe("motd=after\n")
        ->and(file_get_contents($reference->snapshotPath))->toBe("motd=before\n");
});

it('every discovered file is already a valid, safely constructed MinecraftPath', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");
    mkdir($this->minecraftRoot.'/plugins/Geyser-Spigot', 0755, true);
    file_put_contents($this->minecraftRoot.'/plugins/Geyser-Spigot/config.yml', "a: b\n");

    foreach ($this->filesystem->discover() as $discovered) {
        // Re-resolving each discovered path must succeed and land on the
        // exact same absolute path — proving discover() never hands back
        // anything that read()/writeAtomically() would themselves reject.
        $reResolved = MinecraftPath::fromUserInput($discovered->path->relativePath);

        expect($reResolved->absolutePath)->toBe($discovered->path->absolutePath);
    }
});
