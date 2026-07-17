<?php

namespace App\Operations\Exceptions;

use App\Operations\OperationStatus;
use RuntimeException;

/**
 * Thrown whenever code attempts to move an Operation to a status its
 * current status cannot legally reach (see OperationStatus::canTransitionTo()).
 * CraftKeeper never silently ignores an illegal transition attempt.
 */
class IllegalOperationTransition extends RuntimeException
{
    public function __construct(
        public readonly OperationStatus $from,
        public readonly OperationStatus $to,
    ) {
        parent::__construct(sprintf(
            'Cannot transition an operation from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
