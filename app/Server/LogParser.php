<?php

namespace App\Server;

/**
 * Parses raw Minecraft/Paper/Floodgate/Geyser console lines into
 * structured App\Server\LogEvent objects. Pure and stateless — no I/O, no
 * database access; App\Server\LogTailService is the only caller in
 * production and is responsible for sanitizing/bounding lines BEFORE they
 * ever reach parse().
 *
 * The one invariant this class exists to guarantee: parse() NEVER drops a
 * line. It returns exactly one LogEvent per input line, in the same
 * order, and every LogEvent's $raw is the exact line it was built from —
 * a line that matches nothing recognized still comes back as
 * LogEventKind::Unknown with $raw populated, never omitted from the
 * result.
 *
 * Two console line "envelope" shapes are recognized (the message body
 * inside either is then classified the same way):
 *
 *   - "[HH:MM:SS LEVEL]: message"      — Paper/vanilla live console output
 *     (log4j's TerminalConsoleAppender pattern; this is the brief's own
 *     verbatim test format).
 *   - "[HH:MM:SS] [Thread/LEVEL]: message" — the classic logs/latest.log
 *     file pattern (log4j's file-appender pattern, thread-qualified).
 *
 * A line matching neither envelope is classified directly as the "message"
 * (so a bare, prefix-less join/leave/kick/chat line is still recognized);
 * if nothing recognizes it either way, it is LogEventKind::Unknown.
 *
 * Platform detection (Task 11's ambiguity resolution #4): Bedrock is
 * assigned ONLY for a Floodgate "logged in as" line, which is the sole
 * signal that actually states the platform. Standard join/leave lines
 * carry no such signal and default to Java (Floodgate always logs its own
 * preceding line for a Bedrock player before the vanilla join line fires —
 * see docs/architecture/decisions.md for the full reasoning). Kick lines
 * don't reliably indicate a platform either way and are left null rather
 * than guessed.
 */
final class LogParser
{
    private const ENVELOPE_INLINE_LEVEL = '/^\[\d{2}:\d{2}:\d{2}\s+[A-Za-z]+\]:\s?(.*)$/su';

    private const ENVELOPE_THREAD_LEVEL = '/^\[\d{2}:\d{2}:\d{2}\]\s\[[^\]\/]+\/[A-Za-z]+\]:\s?(.*)$/su';

    private const FLOODGATE_JOIN = '/^\[floodgate\]\s+Floodgate player logged in as (\S+)/u';

    private const VANILLA_JOIN = '/^(\S+) joined the game$/u';

    private const VANILLA_LEAVE = '/^(\S+) left the game$/u';

    private const VANILLA_KICK = '/^(\S+) was kicked(?:\s+for\s+(.+))?$/u';

    private const VANILLA_CHAT = '/^<(\S+)>\s(.*)$/u';

    /**
     * @param  list<string>  $lines
     * @return list<LogEvent>
     */
    public function parse(array $lines): array
    {
        return array_map($this->parseLine(...), $lines);
    }

    private function parseLine(string $line): LogEvent
    {
        $message = $this->extractMessage($line);
        $event = $this->classifyMessage($message ?? $line, $line);

        return $event ?? new LogEvent(LogEventKind::Unknown, null, null, null, $line);
    }

    /**
     * Strips a recognized "[time level]:" / "[time] [thread/level]:"
     * envelope, returning just the message body. Returns null when
     * neither envelope matches, so the caller falls back to classifying
     * the whole line as-is.
     */
    private function extractMessage(string $line): ?string
    {
        if (preg_match(self::ENVELOPE_INLINE_LEVEL, $line, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match(self::ENVELOPE_THREAD_LEVEL, $line, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function classifyMessage(string $message, string $raw): ?LogEvent
    {
        if (preg_match(self::FLOODGATE_JOIN, $message, $matches) === 1) {
            return new LogEvent(LogEventKind::Join, $matches[1], PlayerPlatform::Bedrock, null, $raw);
        }

        if (preg_match(self::VANILLA_JOIN, $message, $matches) === 1) {
            return new LogEvent(LogEventKind::Join, $matches[1], PlayerPlatform::Java, null, $raw);
        }

        if (preg_match(self::VANILLA_LEAVE, $message, $matches) === 1) {
            return new LogEvent(LogEventKind::Leave, $matches[1], PlayerPlatform::Java, null, $raw);
        }

        if (preg_match(self::VANILLA_KICK, $message, $matches) === 1) {
            return new LogEvent(LogEventKind::Kick, $matches[1], null, $matches[2] ?? null, $raw);
        }

        if (preg_match(self::VANILLA_CHAT, $message, $matches) === 1) {
            return new LogEvent(LogEventKind::Chat, $matches[1], null, $matches[2], $raw);
        }

        return null;
    }
}
