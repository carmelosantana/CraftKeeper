<?php

namespace App\Filesystem;

use App\Config\DiscoveredFile;

/**
 * The one and only port through which CraftKeeper's application services
 * (config, plugin, and future server ports) read or write the mounted
 * Minecraft directory. Every method accepts or returns values already
 * proven contained by MinecraftPath — see that class for the containment
 * algorithm this whole boundary rests on.
 *
 * This is the plan's Stable Interface, reproduced exactly:
 *
 *     interface MinecraftFilesystem
 *     {
 *         public function discover(): array; // list<DiscoveredFile>
 *         public function read(MinecraftPath $path): FileSnapshot;
 *         public function writeAtomically(MinecraftPath $path, string $contents, string $expectedSha256): FileSnapshot;
 *         public function copyToSnapshot(MinecraftPath $path, string $operationId): SnapshotReference;
 *     }
 */
interface MinecraftFilesystem
{
    /**
     * @return list<DiscoveredFile>
     */
    public function discover(): array;

    public function read(MinecraftPath $path): FileSnapshot;

    public function writeAtomically(MinecraftPath $path, string $contents, string $expectedSha256): FileSnapshot;

    public function copyToSnapshot(MinecraftPath $path, string $operationId): SnapshotReference;
}
