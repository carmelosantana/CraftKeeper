<?php

namespace App\Ai;

use App\Config\ParsedConfig;
use App\Config\Schemas\ConfigSchema;
use App\Models\Secret;
use App\Operations\InputRedactor;

/**
 * Replaces every occurrence of a KNOWN secret VALUE inside arbitrary text
 * with App\Operations\InputRedactor::MASK, before that text is ever
 * allowed to leave CraftKeeper to a hosted AI provider — the crux
 * boundary Task 16 exists to guarantee (see the task brief's Step 1 test
 * and docs/architecture/decisions.md).
 *
 * This is deliberately VALUE-based, not key-name-based:
 * App\Operations\InputRedactor (Task 5) already redacts by array key
 * pattern (a value stored under a key literally named "password"), which
 * is the right defense for operation metadata but cannot help here — an
 * arbitrary chunk of config file text or a free-typed chat message has no
 * key names at all, only bytes that happen to equal a secret CraftKeeper
 * already knows about. redact() is the low-level primitive (exactly the
 * shape the task brief's own test calls); redactKnownSecrets() is the
 * higher-level convenience that gathers BOTH halves of the task's
 * ambiguity resolution #2: every configured secret (from the Secret
 * store, Task 4) and every discovered secret (schema `secret: true`
 * field VALUES actually present in a parsed config, Task 7) into one
 * value list before delegating to redact().
 *
 * A value is masked wherever it appears in the text, regardless of which
 * key it came from — so a secret pasted into a chat message, embedded in
 * a config excerpt, or echoed inside a log line is caught the same way.
 * Disclosures are one per DISTINCT value actually found (never one per
 * occurrence, and never the value itself) — see App\Ai\RedactionResult.
 */
final class SecretRedactor
{
    /**
     * @param  list<mixed>  $secretValues  Expected to be strings; defensively skips anything else — see the loop below.
     * @param  array<string, string>  $labels  value => human label, for richer disclosures. Optional.
     */
    public function redact(string $text, array $secretValues, array $labels = []): RedactionResult
    {
        $redacted = $text;
        $disclosures = [];
        $seen = [];

        foreach ($secretValues as $value) {
            if (! is_string($value) || $value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;

            $occurrences = substr_count($redacted, $value);

            if ($occurrences === 0) {
                continue;
            }

            $redacted = str_replace($value, InputRedactor::MASK, $redacted);
            $disclosures[] = new RedactionDisclosure($labels[$value] ?? null, $occurrences);
        }

        return new RedactionResult($redacted, $disclosures);
    }

    /**
     * Every currently CONFIGURED secret value in the Secret store (Task
     * 4) — e.g. the RCON password, the hosted AI API key itself. Reading
     * these decrypts every row; the values never leave this class except
     * as input to redact() (which only ever emits a mask + a count, never
     * the value).
     *
     * @return list<string>
     */
    public function configuredSecretValues(): array
    {
        return array_values($this->configuredSecretLabels());
    }

    /**
     * @return array<string, string> value => Secret key
     */
    public function configuredSecretLabels(): array
    {
        $labels = [];

        /** @var Secret $secret */
        foreach (Secret::query()->get(['key', 'value']) as $secret) {
            if (is_string($secret->value) && $secret->value !== '') {
                $labels[$secret->value] = $secret->key;
            }
        }

        return $labels;
    }

    /**
     * Every schema `secret: true` field VALUE actually present in an
     * already-parsed config (Task 7's ConfigSchemaField::$secret) —
     * mirrors App\Config\ConfigChangeService::summarizeChanges()'s own
     * secret-field detection, but returns the VALUES themselves (for
     * redaction) rather than a masked display string.
     *
     * @return array<string, string> value => schema field path
     */
    public function discoverSchemaSecretLabels(ParsedConfig $parsed, ?ConfigSchema $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $labels = [];

        foreach ($schema->fields as $field) {
            if (! $field->secret) {
                continue;
            }

            $node = $parsed->node($field->path);

            if ($node !== null && is_scalar($node->value) && $node->value !== '') {
                $labels[(string) $node->value] = $field->path;
            }
        }

        return $labels;
    }

    /**
     * The convenience most callers want: redact $text against BOTH every
     * configured Secret value and every schema-discovered secret value
     * present in $parsed (when given) — the exact two sources Task 16's
     * ambiguity resolution #2 requires a hosted-bound redaction pass to
     * cover.
     */
    public function redactKnownSecrets(string $text, ?ParsedConfig $parsed = null, ?ConfigSchema $schema = null): RedactionResult
    {
        $labels = $this->configuredSecretLabels();

        if ($parsed !== null) {
            $labels = array_merge($labels, $this->discoverSchemaSecretLabels($parsed, $schema));
        }

        return $this->redact($text, array_keys($labels), $labels);
    }
}
