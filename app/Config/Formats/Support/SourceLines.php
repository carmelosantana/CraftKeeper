<?php

namespace App\Config\Formats\Support;

/**
 * Splits raw config source text into byte-accurate physical lines without
 * normalizing line endings — the one piece of shared plumbing every
 * scalar-patching adapter (Properties, YAML, TOML) needs so it can locate
 * a value's exact byte span and rewrite only that span, leaving every
 * other byte (including whichever line-ending style the file already
 * uses, even mixed) untouched.
 */
final class SourceLines
{
    /**
     * @return list<SourceLine>
     */
    public static function split(string $contents): array
    {
        $lines = [];
        $offset = 0;
        $length = strlen($contents);
        $lineNumber = 1;

        while ($offset <= $length) {
            $newlineAt = strpos($contents, "\n", $offset);

            if ($newlineAt === false) {
                // Final line, possibly with no trailing newline at all.
                $content = substr($contents, $offset);
                $lines[] = new SourceLine($lineNumber, $offset, $content, 0);

                break;
            }

            $hasCarriageReturn = $newlineAt > $offset && $contents[$newlineAt - 1] === "\r";
            $contentEnd = $hasCarriageReturn ? $newlineAt - 1 : $newlineAt;
            $content = substr($contents, $offset, $contentEnd - $offset);
            $terminatorLength = $newlineAt - $contentEnd + 1;

            $lines[] = new SourceLine($lineNumber, $offset, $content, $terminatorLength);

            $offset = $newlineAt + 1;
            $lineNumber++;
        }

        return $lines;
    }
}
