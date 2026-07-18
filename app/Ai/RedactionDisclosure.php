<?php

namespace App\Ai;

/**
 * One secret value App\Ai\SecretRedactor found and masked. `$label` is a
 * human name for what was redacted (e.g. a Secret store key like
 * "rcon.password", or a config schema field path) when the caller knows
 * one; the redactor itself never reveals the masked VALUE here — only
 * that something was found and how many times.
 */
final readonly class RedactionDisclosure
{
    public function __construct(
        public ?string $label,
        public int $occurrences,
    ) {}
}
