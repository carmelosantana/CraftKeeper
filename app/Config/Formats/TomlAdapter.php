<?php

namespace App\Config\Formats;

use App\Config\ConfigChange;
use App\Config\ConfigChangeKind;
use App\Config\ConfigDiagnostic;
use App\Config\ConfigFormatAdapter;
use App\Config\ConfigNode;
use App\Config\DiagnosticSeverity;
use App\Config\Exceptions\ConfigParseException;
use App\Config\Exceptions\InvalidConfigChange;
use App\Config\Formats\Support\DotPath;
use App\Config\Formats\Support\SequentialApplyResult;
use App\Config\Formats\Support\SourceLines;
use App\Config\ParsedConfig;
use App\Config\Schemas\ConfigSchema;
use App\Config\Schemas\SchemaValidator;
use App\Config\SourceLocation;
use App\Config\ValidationResult;
use App\Filesystem\MinecraftPath;
use Throwable;
use Yosymfony\Toml\Exception\ParseException as TomlParseException;
use Yosymfony\Toml\Toml;

/**
 * The TOML format (e.g. a plugin's config.toml or a proxy's velocity.toml
 * if one is ever discovered), backed by yosymfony/toml for parsing.
 *
 * Same scalar-leaf-patch-else-normalize strategy as YamlAdapter, adapted
 * to TOML's own nesting convention: `[table.name]` headers set the
 * dotted prefix for every `key = value` line that follows, until the
 * next header (rather than YAML's indentation). `[[array.of.tables]]`
 * headers, and any value that is an array `[...]` or inline table
 * `{...}`, are treated as opaque — out of scope for scalar-leaf
 * patching, exactly like YamlAdapter's sequence handling — because a
 * TOML array-of-tables element has no single stable dotted path either.
 *
 * TOML has no null/nil scalar type at all (unlike Properties, YAML, and
 * JSON) — see the TOML spec — so a ConfigChange whose value is `null`
 * throws InvalidConfigChange rather than silently inventing a
 * non-standard representation.
 *
 * A brand-new top-level key (Add on a dotted-free path) is inserted
 * before the file's FIRST `[table]` header rather than simply appended
 * at end-of-file: in TOML, any `key = value` line appearing after a
 * table header belongs to that table, not the document root, so
 * appending at EOF would silently reparent the new key into whatever
 * table happens to be last.
 */
final class TomlAdapter implements ConfigFormatAdapter
{
    public function supports(MinecraftPath $path, string $contents): bool
    {
        return str_ends_with(strtolower($path->relativePath), '.toml');
    }

    public function parse(string $contents): ParsedConfig
    {
        $data = $this->decode($contents);
        $nodes = $this->locateScalarLeaves($contents);

        return new ParsedConfig($data, $nodes);
    }

    public function validate(string $contents, ?ConfigSchema $schema): ValidationResult
    {
        try {
            $data = $this->decode($contents);
        } catch (ConfigParseException $e) {
            return ValidationResult::invalid([
                new ConfigDiagnostic(DiagnosticSeverity::Error, $e->getMessage(), $e->parsedLine, $e->parsedColumn ?? 1),
            ]);
        }

        $diagnostics = $schema !== null ? SchemaValidator::validate($data, $schema, flatKeys: false) : [];

        return ValidationResult::fromDiagnostics($diagnostics);
    }

    /**
     * @param  list<ConfigChange>  $changes
     */
    public function applyChanges(string $contents, array $changes, ?ConfigSchema $schema): string
    {
        return $this->applySequentially($contents, $changes)->contents;
    }

    /**
     * Non-interface preview — see YamlAdapter's identical method and its
     * applySequentially() docblock. Note this can throw (e.g.
     * InvalidConfigChange) exactly when applyChanges() would throw for
     * the same input, since both run the identical simulation — it never
     * swallows a failure into a false "safe" `false`.
     *
     * @param  list<ConfigChange>  $changes
     */
    public function willNormalize(string $contents, array $changes, ?ConfigSchema $schema): bool
    {
        return $this->applySequentially($contents, $changes)->normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $contents): array
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new ConfigParseException('The file is not valid UTF-8 text.', 1, 1);
        }

        try {
            $data = Toml::parse($contents);
        } catch (TomlParseException $e) {
            $line = $e->getParsedLine();
            throw new ConfigParseException($e->getMessage(), $line >= 0 ? $line : null, null, $e);
        } catch (Throwable $e) {
            throw new ConfigParseException($e->getMessage(), null, null, $e);
        }

        if ($data === null) {
            return [];
        }

        if (! is_array($data)) {
            throw new ConfigParseException('The document root must be a table.', 1, 1);
        }

        return $data;
    }

    /**
     * @return list<ConfigNode>
     */
    private function locateScalarLeaves(string $contents): array
    {
        $nodesByPath = [];
        $tablePrefix = '';
        $opaque = false;

        foreach (SourceLines::split($contents) as $line) {
            $content = $line->content;
            $trimmedLeft = ltrim($content);

            if ($trimmedLeft === '' || $trimmedLeft[0] === '#') {
                continue;
            }

            if (str_starts_with($trimmedLeft, '[[')) {
                // Array-of-tables header — every key under it belongs to
                // one array element, not one stable dotted path.
                $opaque = true;

                continue;
            }

            if ($trimmedLeft[0] === '[') {
                $header = $this->parseTableHeader($trimmedLeft);

                if ($header === null) {
                    $opaque = true;

                    continue;
                }

                $tablePrefix = $header;
                $opaque = false;

                continue;
            }

            if ($opaque) {
                continue;
            }

            $match = $this->matchKeyValueLine($content);

            if ($match === null) {
                continue;
            }

            [$key, $valueStart, $valueLength] = $match;
            $valueText = substr($content, $valueStart, $valueLength);

            if ($valueText === '' || ! $this->isSimpleScalarToken($valueText)) {
                continue;
            }

            $path = $tablePrefix === '' ? $key : $tablePrefix.'.'.$key;

            $nodesByPath[$path] = new ConfigNode($path, $this->decodeScalar($valueText), new SourceLocation(
                $line->number,
                $valueStart + 1,
                $line->offset + $valueStart,
                strlen($valueText),
            ));
        }

        return array_values($nodesByPath);
    }

    private function parseTableHeader(string $trimmedLine): ?string
    {
        $withoutComment = rtrim($this->stripComment($trimmedLine));

        if (! str_starts_with($withoutComment, '[') || ! str_ends_with($withoutComment, ']')) {
            return null;
        }

        $inner = trim(substr($withoutComment, 1, -1));

        if ($inner === '') {
            return null;
        }

        $segments = array_map(
            fn (string $segment): string => trim(trim(trim($segment), '"'), "'"),
            explode('.', $inner),
        );

        return implode('.', $segments);
    }

    /**
     * @return ?array{0: string, 1: int, 2: int} [key, valueStartOffsetInLine, valueLength]
     */
    private function matchKeyValueLine(string $content): ?array
    {
        $len = strlen($content);
        $i = 0;

        while ($i < $len && ($content[$i] === ' ' || $content[$i] === "\t")) {
            $i++;
        }

        if ($i >= $len) {
            return null;
        }

        if ($content[$i] === '"' || $content[$i] === "'") {
            $quote = $content[$i];
            $j = $i + 1;
            $closed = false;

            while ($j < $len) {
                if ($quote === '"' && $content[$j] === '\\') {
                    $j += 2;

                    continue;
                }

                if ($content[$j] === $quote) {
                    $closed = true;
                    $j++;

                    break;
                }

                $j++;
            }

            if (! $closed) {
                return null;
            }

            $key = substr($content, $i + 1, $j - $i - 2);
            $keyEnd = $j;
        } else {
            $keyStart = $i;

            while ($i < $len && $content[$i] !== '=' && $content[$i] !== '#') {
                $i++;
            }

            if ($i >= $len || $content[$i] !== '=') {
                return null;
            }

            $key = rtrim(substr($content, $keyStart, $i - $keyStart));

            if ($key === '') {
                return null;
            }

            $keyEnd = $i;
        }

        $afterKey = $keyEnd;

        while ($afterKey < $len && ($content[$afterKey] === ' ' || $content[$afterKey] === "\t")) {
            $afterKey++;
        }

        if ($afterKey >= $len || $content[$afterKey] !== '=') {
            return null;
        }

        $valueStart = $afterKey + 1;

        while ($valueStart < $len && $content[$valueStart] === ' ') {
            $valueStart++;
        }

        $rawValueSegment = substr($content, $valueStart);
        $valueSegment = $this->stripComment($rawValueSegment);
        $trimmedValue = rtrim($valueSegment);

        return [$key, $valueStart, strlen($trimmedValue)];
    }

    private function isSimpleScalarToken(string $value): bool
    {
        return $value !== '' && ! in_array($value[0], ['[', '{'], true);
    }

    private function decodeScalar(string $token): mixed
    {
        try {
            $result = Toml::parse('ck_scalar = '.$token);

            return is_array($result) ? ($result['ck_scalar'] ?? null) : null;
        } catch (Throwable) {
            return $token;
        }
    }

    /**
     * TOML comments start with an unquoted "#" anywhere on the line (no
     * preceding-whitespace requirement, unlike YAML) and run to end of
     * line. Only truncates the tail, so byte offsets before the
     * truncation point stay valid.
     */
    private function stripComment(string $segment): string
    {
        $result = '';
        $len = strlen($segment);
        $inSingle = false;
        $inDouble = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $segment[$i];

            if ($inSingle) {
                $result .= $ch;

                if ($ch === "'") {
                    $inSingle = false;
                }

                continue;
            }

            if ($inDouble) {
                $result .= $ch;

                if ($ch === '\\' && $i + 1 < $len) {
                    $result .= $segment[++$i];

                    continue;
                }

                if ($ch === '"') {
                    $inDouble = false;
                }

                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                $result .= $ch;

                continue;
            }

            if ($ch === '"') {
                $inDouble = true;
                $result .= $ch;

                continue;
            }

            if ($ch === '#') {
                break;
            }

            $result .= $ch;
        }

        return $result;
    }

    /**
     * @param  list<ConfigNode>  $nodes
     */
    private function findNode(array $nodes, string $path): ?ConfigNode
    {
        foreach ($nodes as $node) {
            if ($node->path === $path) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Classifies a single change against whatever $contents it is handed
     * — never re-decodes or re-derives $contents itself. Callers that
     * need to classify a whole batch (applySequentially(), below) are
     * responsible for feeding each successive change the content as
     * mutated by every change before it, so this always reflects reality
     * for a multi-change batch rather than only the first change in it.
     *
     * @return 'patch'|'append'|'remove-line'|'noop'|'normalize'
     */
    private function classify(string $contents, ConfigChange $change): string
    {
        $existing = $this->findNode($this->locateScalarLeaves($contents), $change->path);

        if ($change->kind === ConfigChangeKind::Remove) {
            if ($existing !== null) {
                return 'remove-line';
            }

            try {
                $data = $this->decode($contents);
            } catch (ConfigParseException) {
                return 'normalize';
            }

            return DotPath::has($data, $change->path) ? 'normalize' : 'noop';
        }

        if ($existing !== null) {
            return 'patch';
        }

        // Not locatable as a scalar — either genuinely new, or an
        // existing non-scalar (array/inline-table) value our locator
        // deliberately doesn't index. Only a truly new path is safe to
        // append; appending over an existing key would create a
        // duplicate, which TOML itself treats as a hard error.
        try {
            $data = $this->decode($contents);
        } catch (ConfigParseException) {
            return 'normalize';
        }

        if (DotPath::has($data, $change->path)) {
            return 'normalize';
        }

        return str_contains($change->path, '.') ? 'normalize' : 'append';
    }

    /**
     * The ONE sequential-classification pass shared by applyChanges()
     * and willNormalize() — see YamlAdapter's identical method for the
     * full rationale. Applying a step can throw (e.g. patchScalar()'s
     * renderScalar() rejecting a value TOML can't represent on a single
     * line); that exception is left to propagate from BOTH callers
     * rather than caught here, since a step that would actually fail is
     * never "safe" to classify as anything else.
     *
     * @param  list<ConfigChange>  $changes
     */
    private function applySequentially(string $contents, array $changes): SequentialApplyResult
    {
        $normalized = false;

        foreach ($changes as $change) {
            $classification = $this->classify($contents, $change);
            $normalized = $normalized || $classification === 'normalize';
            $contents = $this->applyClassified($contents, $change, $classification);
        }

        return new SequentialApplyResult($contents, $normalized);
    }

    /**
     * @param  'patch'|'append'|'remove-line'|'noop'|'normalize'  $classification
     */
    private function applyClassified(string $contents, ConfigChange $change, string $classification): string
    {
        return match ($classification) {
            'patch' => $this->patchScalar($contents, $change),
            'append' => $this->appendTopLevelKey($contents, $change),
            'remove-line' => $this->removeScalarLine($contents, $change),
            'noop' => $contents,
            'normalize' => $this->normalize($contents, $change),
        };
    }

    private function patchScalar(string $contents, ConfigChange $change): string
    {
        $node = $this->findNode($this->locateScalarLeaves($contents), $change->path);
        $rendered = $this->renderScalar($change);

        return substr_replace($contents, $rendered, $node->location->offset, $node->location->length);
    }

    private function appendTopLevelKey(string $contents, ConfigChange $change): string
    {
        $rendered = $this->renderScalar($change);
        $line = $change->path.' = '.$rendered;

        $insertOffset = $this->findFirstTableHeaderOffset($contents);

        if ($insertOffset === null) {
            if (trim($contents) === '') {
                return $line."\n";
            }

            $endsWithNewline = str_ends_with($contents, "\n") || str_ends_with($contents, "\r");

            return $contents.($endsWithNewline ? '' : "\n").$line."\n";
        }

        return substr($contents, 0, $insertOffset).$line."\n".substr($contents, $insertOffset);
    }

    private function findFirstTableHeaderOffset(string $contents): ?int
    {
        foreach (SourceLines::split($contents) as $line) {
            $trimmed = ltrim($line->content);

            if ($trimmed !== '' && $trimmed[0] === '[') {
                return $line->offset;
            }
        }

        return null;
    }

    private function removeScalarLine(string $contents, ConfigChange $change): string
    {
        $node = $this->findNode($this->locateScalarLeaves($contents), $change->path);

        foreach (SourceLines::split($contents) as $line) {
            if ($line->number === $node->location->line) {
                return substr($contents, 0, $line->offset).substr($contents, $line->offset + $line->totalLength());
            }
        }

        return $contents;
    }

    private function normalize(string $contents, ConfigChange $change): string
    {
        $data = $this->decode($contents);

        $data = match ($change->kind) {
            ConfigChangeKind::Replace, ConfigChangeKind::Add => DotPath::set($data, $change->path, $this->assertTomlRepresentable($change)),
            ConfigChangeKind::Remove => DotPath::unset($data, $change->path),
        };

        return $this->dump($data);
    }

    private function renderScalar(ConfigChange $change): string
    {
        $value = $this->assertTomlRepresentable($change);

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->renderFloat($value),
            is_string($value) => $this->renderTomlString($value),
            default => throw InvalidConfigChange::forChange($change, 'unsupported value type '.get_debug_type($value).' for a TOML file'),
        };
    }

    private function assertTomlRepresentable(ConfigChange $change): mixed
    {
        if ($change->value === null) {
            throw InvalidConfigChange::forChange($change, 'TOML has no null/nil scalar type');
        }

        return $change->value;
    }

    private function renderFloat(float $value): string
    {
        $rendered = (string) $value;

        if (! str_contains($rendered, '.') && ! str_contains($rendered, 'e') && ! str_contains($rendered, 'E')) {
            $rendered .= '.0';
        }

        return $rendered;
    }

    private function renderTomlString(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"'.$escaped.'"';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dump(array $data): string
    {
        $lines = [];
        $this->dumpTable($data, [], $lines);

        return implode("\n", $lines)."\n";
    }

    /**
     * A minimal, deliberately simple TOML dumper for the "full
     * re-serialize" fallback path only — yosymfony/toml ships a
     * TomlBuilder, but it requires imperatively calling addTable()/
     * addValue() in document order rather than accepting a nested PHP
     * array, so a small dumper is less code than adapting to that API.
     * Every scalar value is rendered via renderScalar()'s own logic
     * (inlined here since it works from a raw value, not a ConfigChange).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $prefix
     * @param  list<string>  $lines
     */
    private function dumpTable(array $data, array $prefix, array &$lines): void
    {
        $scalarLines = [];
        $tables = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $tables[$key] = $value;

                continue;
            }

            $scalarLines[] = $key.' = '.$this->dumpValue($value);
        }

        if ($scalarLines !== []) {
            if ($prefix !== []) {
                $lines[] = '['.implode('.', $prefix).']';
            }

            array_push($lines, ...$scalarLines);
        }

        foreach ($tables as $key => $value) {
            $this->dumpTable($value, [...$prefix, (string) $key], $lines);
        }
    }

    private function dumpValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->renderFloat($value),
            is_string($value) => $this->renderTomlString($value),
            is_array($value) && array_is_list($value) => '['.implode(', ', array_map($this->dumpValue(...), $value)).']',
            $value === null => throw new \RuntimeException('TOML has no null/nil scalar type.'),
            default => throw new \RuntimeException('Unsupported TOML value type: '.get_debug_type($value)),
        };
    }
}
