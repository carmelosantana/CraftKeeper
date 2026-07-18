<?php

namespace App\Server;

/**
 * The result of App\Server\ServerVersionDetector::detect(). $known is
 * false — never a fabricated/guessed label — whenever neither a server
 * JAR filename nor a startup log line yielded a real version string (Task
 * 12's "no fabricated zero" requirement, applied to version data).
 */
final readonly class ServerVersion
{
    private function __construct(
        public bool $known,
        public ?string $label,
        public ?string $source,
        public ?string $reason,
    ) {}

    public static function known(string $label, string $source): self
    {
        return new self(true, $label, $source, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, null, null, $reason);
    }
}
