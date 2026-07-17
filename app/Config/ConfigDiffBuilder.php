<?php

namespace App\Config;

use App\Config\Schemas\ConfigSchema;
use App\Operations\InputRedactor;
use Throwable;

/**
 * Builds a review-safe unified diff between two versions of a config
 * file's raw source text, for App\Config\ConfigChangeService's proposal
 * metadata and App\Models\ConfigRevision's stored history entry.
 *
 * Critical safety property: BEFORE either string is diffed, every
 * secret-flagged schema field's value (per App\Config\Schemas\
 * ConfigSchemaField::$secret) is masked to InputRedactor::MASK directly in
 * the raw source bytes, using the exact same byte-offset span each format
 * adapter itself locates via ConfigFormatAdapter::parse()->nodes — the
 * same mechanism the adapters use to PATCH a value is reused here to
 * REDACT it. This redacts a secret field's value everywhere it appears in
 * either version, not just where a change touches it, so an unrelated
 * secret sitting a few lines away from an edited field can never leak
 * into the diff as unchanged "context" either. If the content can't be
 * parsed at all (so secret locations can't be proven), this fails closed
 * — it returns a placeholder rather than ever risking raw, unredacted
 * bytes reaching a diff.
 */
final class ConfigDiffBuilder
{
    /**
     * Above this many (before-lines x after-lines) comparison cells, the
     * O(n*m) LCS diff below is skipped in favor of a coarse "changed"
     * placeholder — config files are realistically tens to a few hundred
     * lines, so this is a defensive ceiling, not a real-world limit.
     */
    private const MAX_DIFF_CELLS = 400_000;

    /**
     * @param  list<string>  $changingSecretPaths  Paths this proposal is actually changing (per App\Config\ConfigChangeRequest::$changes) that are ALSO schema-flagged secret. Masking alone would make an old and new secret value collapse to the identical masked text and vanish from the diff as "no change" even though a real edit happened — see redactSecrets()'s docblock for how this list prevents that without ever revealing the value itself.
     */
    public static function build(
        ConfigFormatAdapter $adapter,
        ?ConfigSchema $schema,
        string $label,
        string $before,
        string $after,
        array $changingSecretPaths = [],
    ): string {
        $redactedBefore = self::redactSecrets($adapter, $schema, $before, $changingSecretPaths, markChanged: false);
        $redactedAfter = self::redactSecrets($adapter, $schema, $after, $changingSecretPaths, markChanged: true);

        if ($redactedBefore === $redactedAfter) {
            return '';
        }

        $beforeLines = self::splitLines($redactedBefore);
        $afterLines = self::splitLines($redactedAfter);

        if (count($beforeLines) * count($afterLines) > self::MAX_DIFF_CELLS) {
            return "--- {$label}\n+++ {$label}\n@@ the file changed (too large to render a full diff) @@\n";
        }

        return self::render($label, self::diffOps($beforeLines, $afterLines));
    }

    /**
     * Masks every secret-flagged field's value in $contents. Public so
     * App\Config\ConfigChangeService can reuse it to build redacted
     * before/after display values consistently with the diff.
     *
     * `$changingSecretPaths`/`$markChanged`: a secret field that is NOT
     * part of this proposal masks identically in both the before and
     * after content, so it correctly disappears from the diff as
     * unchanged context. A secret field that IS being changed must still
     * SHOW as changed — the reviewer needs to know a password is being
     * rotated, even without seeing to what — but its old and new masked
     * text would otherwise be byte-identical (both InputRedactor::MASK),
     * which a line-diff would then also collapse into "no change". To
     * prevent that without ever encoding anything about the real value,
     * the AFTER call (only, via $markChanged) appends a fixed, constant,
     * invisible marker (U+200B ZERO WIDTH SPACE) to masked lines whose
     * path is in $changingSecretPaths — forcing the diff to treat that
     * line as textually different from its BEFORE counterpart, so it
     * renders as a `-`/`+` pair, while both still display as the same
     * six-bullet mask to a human reader.
     *
     * @param  list<string>  $changingSecretPaths
     */
    public static function redactSecrets(
        ConfigFormatAdapter $adapter,
        ?ConfigSchema $schema,
        string $contents,
        array $changingSecretPaths = [],
        bool $markChanged = false,
    ): string {
        if ($schema === null) {
            return $contents;
        }

        $secretPaths = array_values(array_map(
            fn ($field) => $field->path,
            array_filter($schema->fields, fn ($field) => $field->secret),
        ));

        if ($secretPaths === []) {
            return $contents;
        }

        try {
            $nodes = $adapter->parse($contents)->nodes;
        } catch (Throwable) {
            // Cannot prove where a secret's value sits without a
            // successful parse — fail closed rather than risk exposing
            // raw bytes that might contain one.
            return '[content could not be parsed for redaction]';
        }

        $toMask = array_values(array_filter(
            $nodes,
            fn ($node) => in_array($node->path, $secretPaths, true) && $node->location->length > 0,
        ));

        // Mask from the end of the string backwards so earlier offsets
        // stay valid as later spans are replaced.
        usort($toMask, fn ($a, $b) => $b->location->offset <=> $a->location->offset);

        foreach ($toMask as $node) {
            $mask = ($markChanged && in_array($node->path, $changingSecretPaths, true))
                ? InputRedactor::MASK."\u{200B}"
                : InputRedactor::MASK;

            $contents = substr_replace($contents, $mask, $node->location->offset, $node->location->length);
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents);

        return $lines === false ? [$contents] : $lines;
    }

    /**
     * Classic O(n*m) LCS-based line diff — config files are small enough
     * that this is fine; see MAX_DIFF_CELLS above for the safety ceiling.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: 'eq'|'del'|'ins', 1: string}>
     */
    private static function diffOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['eq', $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['del', $a[$i]];
                $i++;
            } else {
                $ops[] = ['ins', $b[$j]];
                $j++;
            }
        }

        while ($i < $n) {
            $ops[] = ['del', $a[$i]];
            $i++;
        }

        while ($j < $m) {
            $ops[] = ['ins', $b[$j]];
            $j++;
        }

        return $ops;
    }

    /**
     * @param  list<array{0: 'eq'|'del'|'ins', 1: string}>  $ops
     */
    private static function render(string $label, array $ops): string
    {
        $out = "--- {$label} (current)\n+++ {$label} (proposed)\n";

        foreach ($ops as [$kind, $line]) {
            $prefix = match ($kind) {
                'eq' => ' ',
                'del' => '-',
                'ins' => '+',
            };

            $out .= $prefix.$line."\n";
        }

        return $out;
    }
}
