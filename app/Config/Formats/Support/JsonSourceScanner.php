<?php

namespace App\Config\Formats\Support;

use App\Config\ConfigNode;
use App\Config\SourceLocation;
use RuntimeException;
use Throwable;

/**
 * A small, tolerant, hand-rolled JSON scanner whose only job is to
 * locate every scalar leaf's exact byte span for ParsedConfig::$nodes —
 * used for read-only display (e.g. a source-mode gutter), never as the
 * source of truth for validity. json_decode() (called separately by
 * JsonAdapter::decode()) is the authoritative parse; if this scanner
 * hits anything it doesn't understand it simply stops and returns
 * whatever nodes it already found rather than throwing, since JSON is
 * always fully re-serialized on write anyway (see JsonAdapter) and never
 * needs this scanner's offsets for patching.
 *
 * Array elements are deliberately treated as opaque (path === null,
 * exactly like YamlAdapter's sequence handling): a scalar sitting inside
 * a JSON array has no single stable dotted path, so it is represented
 * in ParsedConfig::$data (via json_decode) but never gets its own node.
 */
final class JsonSourceScanner
{
    private readonly int $length;

    /** @var array<string, ConfigNode> */
    private array $nodes = [];

    public function __construct(private readonly string $contents)
    {
        $this->length = strlen($contents);
    }

    /**
     * @return list<ConfigNode>
     */
    public function scan(): array
    {
        try {
            $this->parseValue($this->skipWhitespace(0), '');
        } catch (Throwable) {
            // Best-effort only — see class docblock.
        }

        return array_values($this->nodes);
    }

    private function parseValue(int $pos, ?string $path): int
    {
        $pos = $this->skipWhitespace($pos);

        if ($pos >= $this->length) {
            throw new RuntimeException('Unexpected end of JSON.');
        }

        return match ($this->contents[$pos]) {
            '{' => $this->parseObject($pos, $path),
            '[' => $this->parseArray($pos, $path),
            '"' => $this->parseStringLeaf($pos, $path),
            default => $this->parseLiteralLeaf($pos, $path),
        };
    }

    private function parseObject(int $pos, ?string $path): int
    {
        $pos = $this->skipWhitespace($pos + 1);

        if ($pos < $this->length && $this->contents[$pos] === '}') {
            return $pos + 1;
        }

        while (true) {
            $pos = $this->skipWhitespace($pos);

            if ($pos >= $this->length || $this->contents[$pos] !== '"') {
                throw new RuntimeException('Expected an object key.');
            }

            $keyEnd = $this->findStringEnd($pos);
            $key = $this->decodeStringToken(substr($this->contents, $pos, $keyEnd - $pos));
            $pos = $this->skipWhitespace($keyEnd);

            if ($pos >= $this->length || $this->contents[$pos] !== ':') {
                throw new RuntimeException('Expected ":" after an object key.');
            }

            $childPath = $path === null ? null : ($path === '' ? $key : $path.'.'.$key);
            $pos = $this->parseValue($pos + 1, $childPath);
            $pos = $this->skipWhitespace($pos);

            if ($pos < $this->length && $this->contents[$pos] === ',') {
                $pos++;

                continue;
            }

            if ($pos < $this->length && $this->contents[$pos] === '}') {
                return $pos + 1;
            }

            throw new RuntimeException('Expected "," or "}" in an object.');
        }
    }

    private function parseArray(int $pos, ?string $path): int
    {
        $pos = $this->skipWhitespace($pos + 1);

        if ($pos < $this->length && $this->contents[$pos] === ']') {
            return $pos + 1;
        }

        while (true) {
            $pos = $this->parseValue($pos, null);
            $pos = $this->skipWhitespace($pos);

            if ($pos < $this->length && $this->contents[$pos] === ',') {
                $pos++;

                continue;
            }

            if ($pos < $this->length && $this->contents[$pos] === ']') {
                return $pos + 1;
            }

            throw new RuntimeException('Expected "," or "]" in an array.');
        }
    }

    private function parseStringLeaf(int $pos, ?string $path): int
    {
        $end = $this->findStringEnd($pos);

        if ($path !== null) {
            $decoded = $this->decodeStringToken(substr($this->contents, $pos, $end - $pos));
            $this->nodes[$path] = new ConfigNode($path, $decoded, $this->locationFor($pos, $end - $pos));
        }

        return $end;
    }

    private function parseLiteralLeaf(int $pos, ?string $path): int
    {
        $start = $pos;
        $end = $pos;

        while ($end < $this->length && ! in_array($this->contents[$end], [',', '}', ']', ' ', "\t", "\n", "\r"], true)) {
            $end++;
        }

        $token = substr($this->contents, $start, $end - $start);

        if ($token === '') {
            throw new RuntimeException('Empty JSON literal.');
        }

        if ($path !== null) {
            $decoded = match ($token) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => json_decode($token),
            };
            $this->nodes[$path] = new ConfigNode($path, $decoded, $this->locationFor($start, $end - $start));
        }

        return $end;
    }

    private function findStringEnd(int $pos): int
    {
        $end = $pos + 1;

        while ($end < $this->length) {
            if ($this->contents[$end] === '\\') {
                $end += 2;

                continue;
            }

            if ($this->contents[$end] === '"') {
                return $end + 1;
            }

            $end++;
        }

        throw new RuntimeException('Unterminated JSON string.');
    }

    private function decodeStringToken(string $rawToken): string
    {
        $decoded = json_decode($rawToken);

        return is_string($decoded) ? $decoded : trim($rawToken, '"');
    }

    private function skipWhitespace(int $pos): int
    {
        while ($pos < $this->length && in_array($this->contents[$pos], [' ', "\t", "\n", "\r"], true)) {
            $pos++;
        }

        return $pos;
    }

    private function locationFor(int $offset, int $length): SourceLocation
    {
        $before = substr($this->contents, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lastNewline = strrpos($before, "\n");
        $column = $lastNewline === false ? $offset + 1 : $offset - $lastNewline;

        return new SourceLocation($line, $column, $offset, $length);
    }
}
