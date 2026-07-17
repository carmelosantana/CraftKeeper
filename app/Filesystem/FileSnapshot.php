<?php

namespace App\Filesystem;

/**
 * An immutable, fully-read snapshot of one file's content and metadata at
 * a single point in time — the return value of both
 * MinecraftFilesystem::read() and MinecraftFilesystem::writeAtomically().
 * Every property is captured from a single, consistent disk read; nothing
 * about a FileSnapshot ever changes after construction.
 */
final readonly class FileSnapshot
{
    public function __construct(
        public MinecraftPath $path,
        public string $contents,
        public string $sha256,
        public int $mode,
        public int $mtime,
    ) {}
}
