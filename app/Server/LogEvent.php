<?php

namespace App\Server;

/**
 * One structured console-log event, produced by App\Server\LogParser.
 * $raw is populated for EVERY LogEvent, recognized or not — this is what
 * makes "never drop a line" true: a caller can always fall back to $raw
 * even when $kind is LogEventKind::Unknown and every other field is null.
 *
 * $player is the exact username string as it appears in the log line —
 * never a looked-up or fabricated UUID/identity (Task 11's ambiguity
 * resolution #4). $message carries a kick's reason or a chat line's body;
 * it is null for kinds that don't carry free text (Join/Leave/Unknown).
 */
final readonly class LogEvent
{
    public function __construct(
        public LogEventKind $kind,
        public ?string $player,
        public ?PlayerPlatform $platform,
        public ?string $message,
        public string $raw,
    ) {}
}
