<?php

use App\Filesystem\Exceptions\InvalidOperationId;
use App\Filesystem\Exceptions\MinecraftFileNotFound;
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
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

it('copies the current bytes under DATA_ROOT/snapshots/{operation-id}/, preserving the relative path', function () {
    mkdir($this->minecraftRoot.'/plugins/Geyser-Spigot', 0755, true);
    file_put_contents($this->minecraftRoot.'/plugins/Geyser-Spigot/config.yml', "remote:\n  auth-type: floodgate\n");

    $path = MinecraftPath::fromUserInput('plugins/Geyser-Spigot/config.yml');
    $reference = (new SnapshotStore)->copy($path, 'op-123');

    $expectedSnapshotPath = $this->dataRoot.'/snapshots/op-123/plugins/Geyser-Spigot/config.yml';

    expect($reference)->toBeInstanceOf(SnapshotReference::class)
        ->and($reference->operationId)->toBe('op-123')
        ->and($reference->relativePath)->toBe('plugins/Geyser-Spigot/config.yml')
        ->and($reference->snapshotPath)->toBe($expectedSnapshotPath)
        ->and(is_file($expectedSnapshotPath))->toBeTrue()
        ->and(file_get_contents($expectedSnapshotPath))->toBe("remote:\n  auth-type: floodgate\n")
        ->and($reference->sha256)->toBe(hash('sha256', "remote:\n  auth-type: floodgate\n"));
});

it('places a root-level file directly under the operation directory', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");

    $path = MinecraftPath::fromUserInput('server.properties');
    $reference = (new SnapshotStore)->copy($path, 'op-456');

    expect($reference->snapshotPath)->toBe($this->dataRoot.'/snapshots/op-456/server.properties');
});

it('keeps snapshots for different operations on the same file independent', function () {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v1\n");
    $path = MinecraftPath::fromUserInput('server.properties');
    $first = (new SnapshotStore)->copy($path, 'op-v1');

    file_put_contents($this->minecraftRoot.'/server.properties', "motd=v2\n");
    $path = MinecraftPath::fromUserInput('server.properties');
    $second = (new SnapshotStore)->copy($path, 'op-v2');

    expect(file_get_contents($first->snapshotPath))->toBe("motd=v1\n")
        ->and(file_get_contents($second->snapshotPath))->toBe("motd=v2\n");
});

it('throws MinecraftFileNotFound when snapshotting a file that does not exist', function () {
    $path = MinecraftPath::fromUserInput('plugins/DoesNotExist/config.yml');

    expect(fn () => (new SnapshotStore)->copy($path, 'op-789'))
        ->toThrow(MinecraftFileNotFound::class);
});

it('rejects an operation id that is not a safe path segment', function (string $operationId) {
    file_put_contents($this->minecraftRoot.'/server.properties', "motd=hi\n");
    $path = MinecraftPath::fromUserInput('server.properties');

    expect(fn () => (new SnapshotStore)->copy($path, $operationId))
        ->toThrow(InvalidOperationId::class);
})->with(['../escape', '..', 'a/b', "op\0zero", '']);

it('produces an immutable SnapshotReference (readonly properties)', function () {
    $reflection = new ReflectionClass(SnapshotReference::class);

    expect($reflection->isReadOnly())->toBeTrue();

    foreach ($reflection->getProperties() as $property) {
        expect($property->isReadOnly())->toBeTrue();
    }
});
