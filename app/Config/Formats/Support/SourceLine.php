<?php

namespace App\Config\Formats\Support;

/**
 * One physical line as produced by SourceLines::split(): `$offset` is the
 * byte offset (into the original source string) where the line's content
 * begins, `$content` excludes any line terminator, and
 * `$terminatorLength` is 0 (no trailing newline — end of file), 1 (`\n`),
 * or 2 (`\r\n`) so callers can compute the exact byte span consumed by
 * the whole physical line (content + terminator) when they need to
 * delete it outright.
 */
final readonly class SourceLine
{
    public function __construct(
        public int $number,
        public int $offset,
        public string $content,
        public int $terminatorLength,
    ) {}

    public function contentLength(): int
    {
        return strlen($this->content);
    }

    public function totalLength(): int
    {
        return $this->contentLength() + $this->terminatorLength;
    }
}
