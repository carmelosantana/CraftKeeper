<?php

namespace App\Config\Formats;

use App\Config\ConfigChange;
use App\Config\ConfigChangeKind;
use App\Config\ConfigDiagnostic;
use App\Config\ConfigFormatAdapter;
use App\Config\ConfigNode;
use App\Config\DiagnosticSeverity;
use App\Config\Exceptions\ConfigParseException;
use App\Config\Formats\Support\DotPath;
use App\Config\Formats\Support\SequentialApplyResult;
use App\Config\Formats\Support\SourceLine;
use App\Config\Formats\Support\SourceLines;
use App\Config\ParsedConfig;
use App\Config\Schemas\ConfigSchema;
use App\Config\Schemas\SchemaValidator;
use App\Config\SourceLocation;
use App\Config\ValidationResult;
use App\Filesystem\MinecraftPath;
use Symfony\Component\Yaml\Exception\ParseException as SymfonyYamlParseException;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The YAML format used by Paper/Geyser/Floodgate config files, backed by
 * symfony/yaml for parsing/validation and dumping. Two things every
 * caller needs to know:
 *
 * 1. YAML anchors and aliases (`&name`, `*name`, including the `<<: *ref`
 *    merge-key form) are rejected outright, per the Task 7 brief — never
 *    expanded. This is enforced twice: a raw-text pre-scan (below) that
 *    rejects an anchor/alias indicator character wherever the YAML
 *    grammar allows one (so even an anchor that's defined but never
 *    referenced is caught), and, as defense in depth,
 *    Yaml::PARSE_EXCEPTION_ON_ALIAS passed to the underlying parser.
 * 2. Only a REPLACE/ADD on an existing (or brand-new top-level) SCALAR
 *    leaf, or a REMOVE of an existing scalar leaf, patches the original
 *    source bytes in place. Anything else — a new nested key, any change
 *    touching an array or object value, or removing a key nested under
 *    a still-populated parent — falls back to a full decode → mutate →
 *    Yaml::dump() re-serialize, which cannot preserve comments. Call
 *    willNormalize() (not part of the fixed ConfigFormatAdapter
 *    interface — see its docblock) before applyChanges() to know which
 *    path a given change set will take.
 */
final class YamlAdapter implements ConfigFormatAdapter
{
    public function supports(MinecraftPath $path, string $contents): bool
    {
        $lower = strtolower($path->relativePath);

        return str_ends_with($lower, '.yml') || str_ends_with($lower, '.yaml');
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
     * Non-interface preview: true if applying this change set would need
     * at least one full structural re-serialize (losing comments) rather
     * than an in-place byte patch. Shares applySequentially() with
     * applyChanges(): each change is classified against the content as
     * mutated by every change before it in the batch — exactly how
     * applyChanges() itself walks the batch — so this can't disagree
     * with what applyChanges() actually does to the SAME input. Only the
     * `normalized` half of the simulation's result is kept; the
     * simulated bytes are discarded, so calling this never mutates or
     * persists anything.
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

        $this->assertNoAnchorsOrAliases($contents);

        try {
            $data = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (SymfonyYamlParseException $e) {
            throw new ConfigParseException($e->getMessage(), $e->getParsedLine() >= 0 ? $e->getParsedLine() : null, null, $e);
        } catch (Throwable $e) {
            throw new ConfigParseException($e->getMessage(), null, null, $e);
        }

        if ($data === null) {
            return [];
        }

        if (! is_array($data)) {
            throw new ConfigParseException('The document root must be a mapping.', 1, 1);
        }

        return $data;
    }

    private function assertNoAnchorsOrAliases(string $contents): void
    {
        foreach (SourceLines::split($contents) as $line) {
            $scrubbed = $this->stripCommentsAndQuotedStrings($line->content);

            if (preg_match('/(?:^|[\s\[{,:-])[&*][^\s,\]}]/', $scrubbed) === 1) {
                throw new ConfigParseException('YAML anchors and aliases are not supported.', $line->number, null);
            }
        }
    }

    /**
     * @return list<ConfigNode>
     */
    private function locateScalarLeaves(string $contents): array
    {
        $lines = SourceLines::split($contents);
        $count = count($lines);

        /** @var array<string, ConfigNode> $nodesByPath */
        $nodesByPath = [];

        /** @var list<array{indent: int, path: ?string}> $stack */
        $stack = [];

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $content = $line->content;
            $trimmedLeft = ltrim($content, ' ');
            $indent = strlen($content) - strlen($trimmedLeft);

            if ($trimmedLeft === '' || $trimmedLeft[0] === '#') {
                continue;
            }

            if ($trimmedLeft === '---' || str_starts_with($trimmedLeft, '--- ') || $trimmedLeft === '...') {
                continue;
            }

            while ($stack !== [] && end($stack)['indent'] >= $indent) {
                array_pop($stack);
            }

            $parentIsOpaque = $stack !== [] && end($stack)['path'] === null;

            if ($trimmedLeft[0] === '-') {
                // A sequence item — everything nested under it belongs to
                // one array element, not one addressable dotted path, so
                // it is out of scope for scalar-leaf patching (arrays
                // always fall back to a full re-serialize on write).
                $stack[] = ['indent' => $indent, 'path' => null];

                continue;
            }

            $keyInfo = $this->matchMappingKeyLine($content, $indent);

            if ($keyInfo === null) {
                continue;
            }

            [$key, $hasInlineValue, $valueStart, $valueLength] = $keyInfo;
            $parentPath = ($stack === [] || $parentIsOpaque) ? null : end($stack)['path'];
            $path = $parentIsOpaque ? null : ($parentPath === null || $parentPath === '' ? $key : $parentPath.'.'.$key);

            if ($hasInlineValue) {
                $valueText = substr($content, $valueStart, $valueLength);

                if ($valueText !== '' && ($valueText[0] === '|' || $valueText[0] === '>')) {
                    // Block scalar: its body is on the following, more-
                    // indented lines — push an opaque frame so those
                    // continuation lines are never misread as mapping
                    // keys of the wrong container.
                    $stack[] = ['indent' => $indent, 'path' => null];

                    continue;
                }

                if (! $parentIsOpaque && $path !== null && $this->isSimpleScalarToken($valueText)) {
                    $nodesByPath[$path] = new ConfigNode($path, $this->decodeScalar($valueText), new SourceLocation(
                        $line->number,
                        $valueStart + 1,
                        $line->offset + $valueStart,
                        strlen($valueText),
                    ));
                }

                continue;
            }

            if ($parentIsOpaque) {
                continue;
            }

            $nextIndent = $this->peekNextSignificantIndent($lines, $i + 1);

            if ($nextIndent !== null && $nextIndent > $indent) {
                $stack[] = ['indent' => $indent, 'path' => $path];

                continue;
            }

            // A bare "key:" with nothing more indented after it — a
            // null-valued leaf, locatable and patchable in place.
            if ($path !== null) {
                $afterColon = rtrim($content);
                $nodesByPath[$path] = new ConfigNode($path, null, new SourceLocation(
                    $line->number,
                    strlen($afterColon) + 1,
                    $line->offset + strlen($afterColon),
                    0,
                ));
            }
        }

        return array_values($nodesByPath);
    }

    /**
     * @return ?array{0: string, 1: bool, 2: int, 3: int} [key, hasInlineValue, valueStartOffsetInLine, valueLength]
     */
    private function matchMappingKeyLine(string $content, int $indent): ?array
    {
        $len = strlen($content);
        $i = $indent;

        if ($i >= $len) {
            return null;
        }

        if ($content[$i] === '"' || $content[$i] === "'") {
            $quote = $content[$i];
            $j = $i + 1;
            $closed = false;

            while ($j < $len) {
                if ($content[$j] === $quote) {
                    if ($quote === "'" && $j + 1 < $len && $content[$j + 1] === "'") {
                        $j += 2;

                        continue;
                    }
                    $closed = true;
                    $j++;

                    break;
                }

                if ($quote === '"' && $content[$j] === '\\') {
                    $j += 2;

                    continue;
                }

                $j++;
            }

            if (! $closed) {
                return null;
            }

            $key = substr($content, $i + 1, $j - $i - 2);
            $keyEnd = $j;
        } else {
            $colon = null;

            for ($j = $i; $j < $len; $j++) {
                if ($content[$j] === ':' && ($j + 1 >= $len || $content[$j + 1] === ' ' || $content[$j + 1] === "\t")) {
                    $colon = $j;

                    break;
                }
            }

            if ($colon === null) {
                return null;
            }

            $key = rtrim(substr($content, $i, $colon - $i));

            if ($key === '' || $key[0] === '[' || $key[0] === '{' || $key[0] === '?') {
                return null;
            }

            $keyEnd = $colon;
        }

        $afterKey = $keyEnd;

        while ($afterKey < $len && $content[$afterKey] === ' ') {
            $afterKey++;
        }

        if ($afterKey >= $len || $content[$afterKey] !== ':') {
            return null;
        }

        $valueStart = $afterKey + 1;

        while ($valueStart < $len && $content[$valueStart] === ' ') {
            $valueStart++;
        }

        $rawValueSegment = substr($content, $valueStart);
        $valueSegment = $this->stripComment($rawValueSegment);
        $trimmedValue = rtrim($valueSegment);

        return [$key, $trimmedValue !== '', $valueStart, strlen($trimmedValue)];
    }

    private function isSimpleScalarToken(string $value): bool
    {
        return ! in_array($value[0], ['[', '{', '|', '>', '&', '*', '!'], true);
    }

    private function decodeScalar(string $token): mixed
    {
        try {
            return Yaml::parse($token);
        } catch (Throwable) {
            return $token;
        }
    }

    /**
     * @param  list<SourceLine>  $lines
     */
    private function peekNextSignificantIndent(array $lines, int $fromIndex): ?int
    {
        $count = count($lines);

        for ($i = $fromIndex; $i < $count; $i++) {
            $content = $lines[$i]->content;
            $trimmed = ltrim($content, ' ');

            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            return strlen($content) - strlen($trimmed);
        }

        return null;
    }

    /**
     * Strips a trailing "# comment" (only when the "#" is unquoted and
     * either starts the segment or is preceded by whitespace, per the
     * YAML spec) without disturbing anything before it — used only on
     * the value portion of a line, so byte offsets before the truncation
     * point remain valid.
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
                    if ($i + 1 < $len && $segment[$i + 1] === "'") {
                        $result .= $segment[++$i];

                        continue;
                    }
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

            if ($ch === '#' && ($i === 0 || $segment[$i - 1] === ' ' || $segment[$i - 1] === "\t")) {
                break;
            }

            $result .= $ch;
        }

        return $result;
    }

    /**
     * Like stripComment(), but additionally blanks out the CONTENTS of
     * any quoted string (not just skipping the comment) — used only by
     * the anchor/alias pre-scan, so a literal "&" or "*" inside a quoted
     * value never trips the rejection.
     */
    private function stripCommentsAndQuotedStrings(string $content): string
    {
        $result = '';
        $len = strlen($content);
        $i = 0;

        while ($i < $len) {
            $ch = $content[$i];

            if ($ch === "'") {
                $result .= ' ';
                $i++;

                while ($i < $len) {
                    if ($content[$i] === "'") {
                        if ($i + 1 < $len && $content[$i + 1] === "'") {
                            $i += 2;

                            continue;
                        }
                        $i++;

                        break;
                    }
                    $i++;
                }

                continue;
            }

            if ($ch === '"') {
                $result .= ' ';
                $i++;

                while ($i < $len) {
                    if ($content[$i] === '\\') {
                        $i += 2;

                        continue;
                    }
                    if ($content[$i] === '"') {
                        $i++;

                        break;
                    }
                    $i++;
                }

                continue;
            }

            if ($ch === '#' && ($i === 0 || $content[$i - 1] === ' ' || $content[$i - 1] === "\t")) {
                break;
            }

            $result .= $ch;
            $i++;
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
        // existing non-scalar (array/object) value our locator
        // deliberately doesn't index. Only a truly new path is safe to
        // append; appending over an existing key would create a
        // duplicate that shadows or corrupts the original.
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
     * and willNormalize(): walks the batch in order, classifying each
     * change against the content as left by every change before it (a
     * scalar leaf a change turns into an array/object, for instance, is
     * no longer a patchable scalar for a LATER change on that same
     * path), and applies it immediately so the next iteration sees the
     * real result. `normalized` is true the moment any single step in
     * that sequence needs a full structural re-serialize — the same
     * condition applyChanges() would hit at that point.
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
        $rendered = Inline::dump($change->value);

        // A bare "key:" line (a null-valued leaf — see
        // locateScalarLeaves()) has a zero-length value span sitting
        // immediately after the colon with no separating space. YAML
        // requires "key: value" (colon THEN space); inserting the
        // rendered value directly there would produce "key:value",
        // which most YAML parsers read as a single plain scalar key
        // named "key:value" instead of key -> value.
        if ($node->location->length === 0) {
            $rendered = ' '.$rendered;
        }

        return substr_replace($contents, $rendered, $node->location->offset, $node->location->length);
    }

    private function appendTopLevelKey(string $contents, ConfigChange $change): string
    {
        $rendered = Inline::dump($change->value);
        $line = $change->path.': '.$rendered;

        if (trim($contents) === '') {
            return $line."\n";
        }

        $endsWithNewline = str_ends_with($contents, "\n") || str_ends_with($contents, "\r");

        return $contents.($endsWithNewline ? '' : "\n").$line."\n";
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
            ConfigChangeKind::Replace, ConfigChangeKind::Add => DotPath::set($data, $change->path, $change->value),
            ConfigChangeKind::Remove => DotPath::unset($data, $change->path),
        };

        return Yaml::dump($data, 10, 2);
    }
}
