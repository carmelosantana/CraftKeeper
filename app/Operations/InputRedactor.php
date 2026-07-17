<?php

namespace App\Operations;

/**
 * A coarse, key-name-based redaction pass applied to every operation's
 * metadata before it is persisted (Operation::redacted_input,
 * ChangeProposal::before/after, AuditEvent::payload) or broadcast.
 *
 * This is deliberately dumb and generic: it does not know what a
 * server.properties key means or which plugin config fields are secrets —
 * that schema-aware redaction belongs to the callers who own that
 * knowledge (Task 8's config diffing, Task 10's CommandPolicy). What it
 * guarantees, uniformly and regardless of operation type, is that a value
 * stored under an obviously sensitive key name (password, token, secret,
 * API key, ...) never reaches the database or the wire as plaintext —
 * a last line of defense, not the only one.
 */
class InputRedactor
{
    public const MASK = '••••••';

    private const SENSITIVE_KEY_PATTERN = '/password|secret|token|credential|api[_-]?key|private[_-]?key/i';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function redact(array $input): array
    {
        $redacted = [];

        foreach ($input as $key => $value) {
            $redacted[$key] = match (true) {
                preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1 => self::MASK,
                is_array($value) => self::redact($value),
                default => $value,
            };
        }

        return $redacted;
    }
}
