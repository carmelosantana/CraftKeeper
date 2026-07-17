<?php

namespace App\Operations;

/**
 * The canonical operation lifecycle. Values are fixed by the CraftKeeper V1
 * plan's Stable Interfaces — do not rename or add cases without updating
 * the plan.
 */
enum OperationStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case RolledBack = 'rolled_back';

    /**
     * The legal state machine graph. Keys are the "from" status; values are
     * the statuses that may legally follow it. A status with no entry (or
     * an empty list) is terminal — nothing may follow it.
     *
     * @return array<string, list<self>>
     */
    private static function graph(): array
    {
        return [
            self::Proposed->value => [self::Approved, self::Rejected],
            self::Approved->value => [self::Running],
            self::Running->value => [self::Succeeded, self::Failed],
            self::Succeeded->value => [self::RolledBack],
            self::Failed->value => [self::RolledBack],
            self::Rejected->value => [],
            self::RolledBack->value => [],
        ];
    }

    /**
     * Whether transitioning from this status to $target is legal.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::graph()[$this->value], true);
    }

    /**
     * The set of statuses that may legally follow this one.
     *
     * @return list<self>
     */
    public function legalNextStatuses(): array
    {
        return self::graph()[$this->value];
    }

    /**
     * Whether this status is terminal (no operation ever leaves it, other
     * than the rollback path from Succeeded/Failed).
     */
    public function isTerminal(): bool
    {
        return $this->legalNextStatuses() === [];
    }
}
