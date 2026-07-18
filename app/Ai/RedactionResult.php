<?php

namespace App\Ai;

/**
 * The result of App\Ai\SecretRedactor::redact(): the text with every
 * matched secret value replaced by App\Operations\InputRedactor::MASK,
 * plus a disclosure per DISTINCT secret value that was actually found and
 * masked (never one per occurrence) — what
 * resources/js/features/assistant/RedactionDisclosure.tsx renders before
 * anything is sent to a hosted provider.
 */
final readonly class RedactionResult
{
    /**
     * @param  list<RedactionDisclosure>  $disclosures
     */
    public function __construct(
        public string $text,
        public array $disclosures,
    ) {}
}
