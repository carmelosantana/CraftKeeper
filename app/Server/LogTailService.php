<?php

namespace App\Server;

use App\Events\ConsoleEntryReceived;
use App\Filesystem\Exceptions\MinecraftRootUnavailable;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftPath;
use App\Models\ConsoleEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Safely tails the Minecraft server's own console log a bounded amount at
 * a time (Task 11's ambiguity resolution #3). Every read is contained to
 * the Minecraft root via App\Filesystem\MinecraftPath — the same boundary
 * Task 6 established for config files, applied here to a fixed, internal
 * path rather than caller-supplied input.
 *
 * Rotation/truncation correctness (the property this class exists to get
 * right, and does — WITH one narrow, disclosed exception described below,
 * "the rotation-straggler window" — "never drop a line"):
 *
 *   - The tailing position is persisted between calls as a
 *     App\Server\TailCursor {inode, offset} JSON file under
 *     {DATA_ROOT}/log-cursors/ (never inside /minecraft — CraftKeeper's
 *     own operational state belongs under DATA_ROOT, mirroring
 *     App\Filesystem\AtomicFileWriter's lock-file convention). This is
 *     REQUIRED, not optional: Laravel's scheduler forks (or, for
 *     closure-based events, re-invokes in the long-lived schedule:work
 *     process) a fresh call on every tick, so in-memory-only state would
 *     not survive between ticks even within one process's lifetime, let
 *     alone a supervisor restart.
 *   - Before reading, the file's CURRENT inode is compared against the
 *     cursor's inode. A different inode means the file was rotated (the
 *     old inode was renamed/moved away and a fresh one created in its
 *     place) — the offset resets to 0 and tailing starts over on the new
 *     file. This is checked BEFORE the file is ever opened for reading,
 *     so a rotation can never cause old-file bytes to be misread as
 *     belonging to the new file.
 *   - DISCLOSED LIMITATION — the rotation-straggler window: this inode
 *     check only stops old-file bytes from being MISREAD as new-file
 *     bytes; it cannot recover bytes the old inode received AFTER this
 *     class's last successful read but BEFORE rotation moved that inode
 *     out from under this path. Real Minecraft/log4j2 rotates
 *     `logs/latest.log` straight to a gzip archive on server restart
 *     (`filePattern=".../%d.log.gz"`), with an unpredictable,
 *     date/index-derived archive filename — there is no cheap, reliable
 *     way for a scheduled (non-daemon) tailer like this one to locate that
 *     archive, decompress it, and drain the handful of straggler bytes
 *     within a single tick, so no such recovery is attempted. In practice
 *     this window is only ever a few lines wide (bounded by how much the
 *     server can log between one tick and the next restart) and only
 *     opens around a server restart — frequent scheduled tailing (this
 *     runs every few seconds; see App\Server docs above) is what keeps it
 *     narrow. This is an accepted V1 trade-off, consistent with "no
 *     long-term log storage in V1" and best-effort observation — see
 *     docs/architecture/decisions.md ("Task 11 Fix — Rotation-Straggler
 *     Window") for the full reasoning, and LogTailServiceTest's "rotation
 *     straggler" test for a characterization of the exact behavior.
 *   - Independently, if the SAME inode's current size is smaller than the
 *     cursor's stored offset, the file was truncated in place (e.g. a
 *     `copytruncate`-style log rotation strategy, or Minecraft itself
 *     truncating on some restart paths) — the offset also resets to 0.
 *     This check runs whether or not the inode changed, so both rotation
 *     strategies are covered.
 *   - At most MAX_READ_BYTES (256 KiB) is read per call. Within that
 *     chunk, only COMPLETE lines (terminated by "\n") are ever processed;
 *     any trailing partial line (the log writer hasn't finished writing
 *     it yet) is left entirely unconsumed — the cursor's offset only
 *     advances past the last complete newline found, never past a partial
 *     line. This is what prevents a line that's mid-write from being
 *     split into two corrupted fragments across two tail() calls: the
 *     NEXT call re-reads from the same offset and will see the full line
 *     once it's complete, still as one line.
 *   - Lines are persisted (as App\Models\ConsoleEntry rows, plus any
 *     derived App\Models\PlayerEvent rows via App\Server\PlayerService)
 *     and broadcast INSIDE one DB transaction; the cursor file is only
 *     advanced AFTER that transaction commits successfully. If anything
 *     throws mid-batch, the cursor is left untouched and the next tick
 *     re-reads and re-processes the same bytes — this makes ingestion
 *     at-least-once, never at-most-once: a crash can (rarely) duplicate a
 *     batch, but can never silently drop one. Given "never drop a line"
 *     is this task's named priority over exactly-once delivery, this is a
 *     deliberate, disclosed trade-off (see docs/architecture/decisions.md).
 *
 * Every persisted/broadcast line is sanitized (ANSI/control sequences
 * stripped) and capped at MAX_ENTRY_BYTES (16 KiB) BEFORE it is parsed,
 * stored, or broadcast — this is also the exact string App\Server\
 * LogParser sees, so a parsed LogEvent's $raw always matches what is
 * actually persisted/broadcast elsewhere, never a longer, un-truncated
 * original.
 */
final class LogTailService
{
    public const DEFAULT_LOG_PATH = 'logs/latest.log';

    /** At most this many bytes are read from the log file per tail() call. */
    public const MAX_READ_BYTES = 262_144; // 256 KiB

    /** Every persisted/broadcast console line is capped at this many bytes. */
    public const MAX_ENTRY_BYTES = 16_384; // 16 KiB

    /** console_entries is trimmed to its most recent rows after every write. */
    public const MAX_CONSOLE_ENTRIES = 2000;

    private const TRUNCATION_SUFFIX = '... [truncated]';

    public function __construct(
        private readonly LogParser $parser,
        private readonly PlayerService $players,
        private readonly string $relativeLogPath = self::DEFAULT_LOG_PATH,
    ) {}

    public function tail(): TailOutcome
    {
        try {
            $path = MinecraftPath::fromUserInput($this->relativeLogPath);
        } catch (MinecraftRootUnavailable|UnsafeMinecraftPath) {
            return TailOutcome::unavailable('The Minecraft root is unavailable.');
        }

        if (! $path->exists) {
            return TailOutcome::unavailable('The server log file was not found.');
        }

        $absolute = $path->absolutePath;
        $inode = @fileinode($absolute);
        $size = @filesize($absolute);

        if ($inode === false || $size === false) {
            return TailOutcome::unavailable('The server log file could not be inspected.');
        }

        $offset = $this->resolveOffset($this->loadCursor(), $inode, $size);

        $handle = @fopen($absolute, 'rb');

        if ($handle === false) {
            return TailOutcome::unavailable('The server log file could not be opened.');
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                return TailOutcome::unavailable('The server log file could not be seeked.');
            }

            $chunk = fread($handle, self::MAX_READ_BYTES);
        } finally {
            fclose($handle);
        }

        if ($chunk === false || $chunk === '') {
            $this->saveCursor($inode, $offset);

            return TailOutcome::upToDate();
        }

        [$lines, $consumedBytes] = $this->splitCompleteLines($chunk);

        if ($lines === []) {
            // No complete line yet — e.g. a very long line still being
            // written. Do NOT advance the offset; the next tick re-reads
            // from the same position once more bytes (and, hopefully, a
            // terminating newline) have been appended.
            return TailOutcome::upToDate();
        }

        $sanitizedLines = array_map($this->sanitizeAndBound(...), $lines);
        $events = $this->parser->parse($sanitizedLines);
        $now = now();

        DB::transaction(function () use ($sanitizedLines, $events, $now): void {
            $entries = [];

            foreach ($sanitizedLines as $line) {
                $entries[] = ConsoleEntry::query()->create([
                    'line' => $line,
                    'occurred_at' => $now,
                ]);
            }

            $this->players->record($events, $now);
            $this->pruneConsoleEntries();

            foreach ($entries as $entry) {
                event(ConsoleEntryReceived::fromEntry($entry));
            }
        });

        $this->saveCursor($inode, $offset + $consumedBytes);

        return TailOutcome::processed(count($sanitizedLines));
    }

    private function resolveOffset(?TailCursor $cursor, int $inode, int $size): int
    {
        if (! $cursor instanceof TailCursor) {
            return 0;
        }

        if ($cursor->inode !== $inode) {
            // Rotated: a new inode exists at this path. Resetting to 0
            // here is what prevents old-file bytes from being misread as
            // belonging to the new file — but it does NOT recover any
            // bytes the old (now-gone) inode received between our last
            // read and the moment it was rotated away: those bytes live
            // only in whatever archive the rotation produced (typically a
            // gzip file with an unpredictable name for real Minecraft/
            // log4j2 rotation), which this class deliberately does not
            // chase. This is a disclosed, accepted gap — "the
            // rotation-straggler window" — not an oversight; see this
            // class's docblock and docs/architecture/decisions.md ("Task
            // 11 Fix — Rotation-Straggler Window").
            return 0;
        }

        if ($cursor->offset > $size) {
            // Truncated in place: the same inode is now smaller than
            // where we last left off.
            return 0;
        }

        return $cursor->offset;
    }

    /**
     * Splits $chunk into complete, "\n"-terminated lines only. Returns the
     * list of lines (with any trailing "\r" stripped) and the number of
     * bytes actually consumed by those complete lines — which may be less
     * than strlen($chunk) when the chunk ends mid-line.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function splitCompleteLines(string $chunk): array
    {
        $lastNewline = strrpos($chunk, "\n");

        if ($lastNewline === false) {
            return [[], 0];
        }

        $consumed = $lastNewline + 1;
        $complete = substr($chunk, 0, $consumed);

        $parts = explode("\n", $complete);
        array_pop($parts); // drop the trailing '' produced by the final "\n"

        $lines = array_map(static fn (string $line): string => rtrim($line, "\r"), $parts);

        return [$lines, $consumed];
    }

    private function sanitizeAndBound(string $line): string
    {
        $sanitized = $this->stripAnsiAndControl($line);

        if (strlen($sanitized) <= self::MAX_ENTRY_BYTES) {
            return $sanitized;
        }

        $keep = self::MAX_ENTRY_BYTES - strlen(self::TRUNCATION_SUFFIX);

        return substr($sanitized, 0, max($keep, 0)).self::TRUNCATION_SUFFIX;
    }

    /**
     * Strips ANSI/VT100 escape sequences (CSI, OSC, and simple two-byte
     * ESC sequences) and any remaining C0 control byte other than tab.
     */
    private function stripAnsiAndControl(string $line): string
    {
        $withoutAnsi = preg_replace(
            '/\x1B\[[0-9;]*[A-Za-z]|\x1B\][^\x07]*(?:\x07|\x1B\\\\)|\x1B[@-_]/',
            '',
            $line,
        ) ?? $line;

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $withoutAnsi) ?? $withoutAnsi;
    }

    /**
     * Trims console_entries to its most recent MAX_CONSOLE_ENTRIES rows.
     * Called inside the same transaction as the batch's inserts, so the
     * table is never observed (even momentarily, by a concurrent reader)
     * holding more than the bound plus this batch's own size.
     */
    private function pruneConsoleEntries(): void
    {
        $cutoffId = ConsoleEntry::query()
            ->orderByDesc('id')
            ->skip(self::MAX_CONSOLE_ENTRIES - 1)
            ->value('id');

        if (is_int($cutoffId)) {
            ConsoleEntry::query()->where('id', '<', $cutoffId)->delete();
        }
    }

    private function cursorPath(): string
    {
        $dataRoot = rtrim((string) config('craftkeeper.data_root'), '/');
        $dir = $dataRoot.'/log-cursors';

        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0755, true, true);
        }

        return $dir.'/'.hash('sha256', $this->relativeLogPath).'.json';
    }

    private function loadCursor(): ?TailCursor
    {
        $path = $this->cursorPath();

        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['inode'], $data['offset']) || ! is_numeric($data['inode']) || ! is_numeric($data['offset'])) {
            return null;
        }

        return new TailCursor((int) $data['inode'], (int) $data['offset']);
    }

    private function saveCursor(int $inode, int $offset): void
    {
        $path = $this->cursorPath();
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));

        file_put_contents($tmp, json_encode(['inode' => $inode, 'offset' => $offset]));
        rename($tmp, $path);
    }
}
