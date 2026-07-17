<?php

namespace App\Config;

/**
 * A precise, byte-accurate span within a config file's raw source text —
 * how a ConfigNode's value is actually found and patched in place.
 * `$offset`/`$length` are byte offsets into the original `$contents`
 * string handed to ConfigFormatAdapter::parse()/applyChanges(); `$line`/
 * `$column` (both 1-indexed) are the human-facing equivalent, used by
 * diagnostics and (eventually) the source editor's gutter.
 */
final readonly class SourceLocation
{
    public function __construct(
        public int $line,
        public int $column,
        public int $offset,
        public int $length,
    ) {}
}
