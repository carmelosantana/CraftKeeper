<?php

namespace App\Config\Formats;

use App\Config\ConfigChange;
use App\Config\ConfigChangeKind;
use App\Config\ConfigDiagnostic;
use App\Config\ConfigFormatAdapter;
use App\Config\ConfigNode;
use App\Config\DiagnosticSeverity;
use App\Config\Exceptions\InvalidConfigChange;
use App\Config\Formats\Support\SourceLine;
use App\Config\Formats\Support\SourceLines;
use App\Config\ParsedConfig;
use App\Config\Schemas\ConfigSchema;
use App\Config\Schemas\SchemaValidator;
use App\Config\SourceLocation;
use App\Config\ValidationResult;
use App\Filesystem\MinecraftPath;

/**
 * The Java `.properties` format used by server.properties. This is the
 * one format that ALWAYS patches the original source in place —
 * PropertiesAdapter::willNormalize() is always false — because the
 * format's flat, line-oriented shape never requires a structural
 * re-serialize: every change reduces to "replace this line's value
 * span," "delete these line(s)," or "append one new line."
 *
 * Format decisions (documented here, not just in code, since they shape
 * every test fixture):
 *
 * - The key/value delimiter is `=` only — Minecraft's server.properties
 *   never uses Java properties' alternate `:` delimiter, and treating
 *   `:` as a delimiter would incorrectly split values that legitimately
 *   contain a colon (e.g. a `motd` of "Hello: World", or a resource pack
 *   URL). A `\`-escaped `=` inside the key portion is honored.
 * - `$path` for both ConfigNode and ConfigChange is the literal property
 *   key text, dots and all (never split into nested segments) — real
 *   server.properties keys legitimately contain dots, e.g. "rcon.port".
 * - Value type coercion: an empty value is `null`; `true`/`false`
 *   (exact, case-sensitive, matching how Minecraft itself writes them)
 *   become PHP bool; a bare optionally-negative integer becomes PHP int;
 *   everything else stays a string.
 * - Comment lines start with `#` or `!` (the Java properties spec
 *   supports both); blank lines and comments are never touched by
 *   applyChanges() and are preserved byte-for-byte.
 * - Duplicate keys: Java's own java.util.Properties#load keeps
 *   overwriting the same map entry as it reads sequentially, so the
 *   LAST occurrence is the effective value — parse() reflects that (the
 *   later line's ConfigNode replaces the earlier one for the same key)
 *   and Replace/Add patch that same last occurrence. Remove deletes
 *   every occurrence of the key, so no stale duplicate can silently
 *   reappear as the effective value later.
 * - Line continuations (a trailing `\` continuing onto the next
 *   physical line) are NOT supported — out of scope; Minecraft's own
 *   generated server.properties never uses them.
 */
final class PropertiesAdapter implements ConfigFormatAdapter
{
    public function supports(MinecraftPath $path, string $contents): bool
    {
        return str_ends_with(strtolower($path->relativePath), '.properties');
    }

    public function parse(string $contents): ParsedConfig
    {
        [$data, $nodes] = $this->scan($contents);

        return new ParsedConfig($data, $nodes);
    }

    public function validate(string $contents, ?ConfigSchema $schema): ValidationResult
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            return ValidationResult::invalid([
                new ConfigDiagnostic(DiagnosticSeverity::Error, 'The file is not valid UTF-8 text.', 1, 1),
            ]);
        }

        $parsed = $this->parse($contents);

        $diagnostics = $schema !== null ? SchemaValidator::validate($parsed->data, $schema, flatKeys: true) : [];

        return ValidationResult::fromDiagnostics($diagnostics);
    }

    public function applyChanges(string $contents, array $changes, ?ConfigSchema $schema): string
    {
        foreach ($changes as $change) {
            $contents = $this->applyOne($contents, $change);
        }

        return $contents;
    }

    /**
     * Properties can always patch its source in place — there is no
     * structural construct (no nesting, no arrays, no comments attached
     * to anything but a whole line) that forces a full re-serialize.
     * Kept as an explicit method (rather than just omitting it) so every
     * adapter exposes the same normalization-preview surface for Task
     * 8/9 to call uniformly regardless of format.
     *
     * @param  list<ConfigChange>  $changes
     */
    public function willNormalize(string $contents, array $changes, ?ConfigSchema $schema): bool
    {
        return false;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<ConfigNode>}
     */
    private function scan(string $contents): array
    {
        $data = [];
        $nodesByKey = [];

        foreach (SourceLines::split($contents) as $line) {
            $trimmedStart = ltrim($line->content);

            if ($trimmedStart === '' || $trimmedStart[0] === '#' || $trimmedStart[0] === '!') {
                continue;
            }

            $delimiterPos = $this->findDelimiter($line->content);

            if ($delimiterPos === null) {
                $key = trim($line->content);

                if ($key === '') {
                    continue;
                }

                $data[$key] = null;
                $nodesByKey[$key] = new ConfigNode($key, null, new SourceLocation(
                    $line->number,
                    $line->contentLength() + 1,
                    $line->offset + $line->contentLength(),
                    0,
                ));

                continue;
            }

            $key = trim(substr($line->content, 0, $delimiterPos));

            if ($key === '') {
                continue;
            }

            $rawValueStart = $delimiterPos + 1;
            $rawValue = substr($line->content, $rawValueStart);
            $leadingWhitespace = strlen($rawValue) - strlen(ltrim($rawValue));
            $valueText = ltrim($rawValue);
            $valueOffset = $line->offset + $rawValueStart + $leadingWhitespace;
            $valueColumn = $rawValueStart + $leadingWhitespace + 1;

            $value = $this->coerce($valueText);

            $data[$key] = $value;
            $nodesByKey[$key] = new ConfigNode($key, $value, new SourceLocation(
                $line->number,
                $valueColumn,
                $valueOffset,
                strlen($valueText),
            ));
        }

        return [$data, array_values($nodesByKey)];
    }

    private function findDelimiter(string $lineContent): ?int
    {
        $escaped = false;

        for ($i = 0, $len = strlen($lineContent); $i < $len; $i++) {
            $char = $lineContent[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '=') {
                return $i;
            }
        }

        return null;
    }

    private function coerce(string $value): int|bool|string|null
    {
        if ($value === '') {
            return null;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if (preg_match('/^-?\d+$/', $value) === 1 && (string) (int) $value === $value) {
            return (int) $value;
        }

        return $value;
    }

    private function applyOne(string $contents, ConfigChange $change): string
    {
        if ($change->kind === ConfigChangeKind::Remove) {
            return $this->removeAllOccurrences($contents, $change->path);
        }

        [, $nodes] = $this->scan($contents);
        $existing = null;

        foreach ($nodes as $node) {
            if ($node->path === $change->path) {
                $existing = $node;

                break;
            }
        }

        if ($existing !== null) {
            return $this->patchValue($contents, $existing, $change);
        }

        return $this->appendKey($contents, $change);
    }

    private function patchValue(string $contents, ConfigNode $node, ConfigChange $change): string
    {
        $rendered = $this->render($change);

        // A "bare key" line (no "=" at all — see scan()) has a
        // zero-length value span sitting at the very end of the key
        // text with no delimiter before it. Inserting the rendered
        // value there without also inserting "=" would silently glue it
        // onto the key text (e.g. "bare-key" + "true" -> "bare-keytrue",
        // corrupting the key itself) rather than adding a value.
        if ($this->lineLacksDelimiter($contents, $node->location->line)) {
            $rendered = '='.$rendered;
        }

        return substr_replace($contents, $rendered, $node->location->offset, $node->location->length);
    }

    private function lineLacksDelimiter(string $contents, int $lineNumber): bool
    {
        foreach (SourceLines::split($contents) as $line) {
            if ($line->number === $lineNumber) {
                return $this->findDelimiter($line->content) === null;
            }
        }

        return false;
    }

    private function appendKey(string $contents, ConfigChange $change): string
    {
        $rendered = $this->render($change);
        $line = $change->path.'='.$rendered;

        if ($contents === '') {
            return $line."\n";
        }

        $endsWithNewline = str_ends_with($contents, "\n") || str_ends_with($contents, "\r");

        return $contents.($endsWithNewline ? '' : "\n").$line."\n";
    }

    private function removeAllOccurrences(string $contents, string $path): string
    {
        /** @var list<SourceLine> $toRemove */
        $toRemove = [];

        foreach (SourceLines::split($contents) as $line) {
            $trimmedStart = ltrim($line->content);

            if ($trimmedStart === '' || $trimmedStart[0] === '#' || $trimmedStart[0] === '!') {
                continue;
            }

            $delimiterPos = $this->findDelimiter($line->content);
            $key = $delimiterPos === null
                ? trim($line->content)
                : trim(substr($line->content, 0, $delimiterPos));

            if ($key === $path) {
                $toRemove[] = $line;
            }
        }

        if ($toRemove === []) {
            return $contents;
        }

        usort($toRemove, fn (SourceLine $a, SourceLine $b): int => $b->offset <=> $a->offset);

        foreach ($toRemove as $line) {
            $contents = substr($contents, 0, $line->offset).substr($contents, $line->offset + $line->totalLength());
        }

        return $contents;
    }

    private function render(ConfigChange $change): string
    {
        $value = $change->value;

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_string($value) => $this->assertSingleLine($change, $value),
            default => throw InvalidConfigChange::forChange($change, 'unsupported value type '.get_debug_type($value).' for a properties file'),
        };
    }

    private function assertSingleLine(ConfigChange $change, string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw InvalidConfigChange::forChange($change, 'a properties value cannot contain a newline');
        }

        return $value;
    }
}
