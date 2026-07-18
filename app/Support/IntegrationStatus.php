<?php

namespace App\Support;

/**
 * One row of App\Support\IntegrationHealthChecker::snapshot() — see that
 * class's docblock. `state` is always one of exactly four values (never a
 * fifth "unknown"/"loading" value invented ad hoc), matching the four
 * states resources/js/components/craftkeeper/StatusBadge.tsx's
 * `STATUS_BADGE_META` renders with a distinct color + shape + label:
 *
 * - `connected`: reachable/configured and verified working right now.
 * - `disabled`: an optional integration the operator has not turned on.
 * - `degraded`: configured/enabled but currently failing or unreachable.
 * - `misconfigured`: enabled/attempted but missing something it needs
 *   (a required field, a signing key, a directory) — distinct from
 *   `degraded` because the fix is a configuration change, not a retry.
 */
final readonly class IntegrationStatus
{
    public function __construct(
        public string $key,
        public string $label,
        public string $state,
        public ?string $reason,
        public bool $testable,
    ) {}

    /**
     * @return array{key: string, label: string, state: string, reason: string|null, testable: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'state' => $this->state,
            'reason' => $this->reason,
            'testable' => $this->testable,
        ];
    }
}
