<?php

namespace App\Console\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Console\RconCommandService::runSafeCommand() when the
 * given command does not classify as App\Console\CommandRisk::Safe. This
 * is the refusal that keeps the "lighter path" (propose + immediate
 * self-approve + execute in one call, per Task 10's ambiguity resolution
 * #4) from ever being reachable for an Elevated command — no Operation is
 * proposed at all when this is thrown, so there is nothing left over to
 * clean up. The raw command text is deliberately never included in the
 * message.
 */
class CommandNotSafe extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This command is not on the predefined safe list and must go through the full propose-approve-execute flow.');
    }
}
