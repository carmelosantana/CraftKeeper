<?php

namespace App\Server;

/**
 * What one App\Server\LogTailService::tail() call actually did — mainly
 * for tests and command output; App\Server\ServerStatusService does NOT
 * depend on this (it checks log-file accessibility directly, so file-based
 * log status stays correct independently of whatever the tailer's last
 * run happened to observe — Task 11's ambiguity resolution #5).
 */
final readonly class TailOutcome
{
    private function __construct(
        public bool $available,
        public ?string $reason,
        public int $linesProcessed,
    ) {}

    public static function processed(int $linesProcessed): self
    {
        return new self(true, null, $linesProcessed);
    }

    public static function upToDate(): self
    {
        return new self(true, null, 0);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, 0);
    }
}
