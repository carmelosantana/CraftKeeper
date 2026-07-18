<?php

namespace App\Server;

/**
 * The file-based-log half of a App\Server\ServerStatusSnapshot. Computed
 * ENTIRELY independently of RconStatus — it never consults
 * App\Models\ServerSample or App\Console\RconClient at all — so an
 * unreachable RCON endpoint can never affect this value (Task 11's
 * ambiguity resolution #5: "file-based logs remain usable independently").
 */
final readonly class LogStatus
{
    private function __construct(
        public bool $available,
        public ?string $reason,
    ) {}

    public static function available(): self
    {
        return new self(true, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason);
    }
}
