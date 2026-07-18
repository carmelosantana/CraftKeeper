<?php

namespace App\Server;

/**
 * Persisted tailing position for one log file: which inode it was reading
 * from, and how many bytes into that inode had already been consumed.
 * App\Server\LogTailService compares a freshly stat()'d inode against
 * this to detect rotation (new inode) and truncation (same inode, smaller
 * size than $offset) between ticks — see its docblock for the full
 * algorithm.
 */
final readonly class TailCursor
{
    public function __construct(
        public int $inode,
        public int $offset,
    ) {}
}
