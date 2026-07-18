<?php

use App\Events\ConsoleEntryReceived;
use App\Models\ConsoleEntry;
use App\Models\Player;
use App\Server\LogTailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
    File::makeDirectory($this->minecraftRoot.'/logs', 0755, true, true);
    $this->logPath = $this->minecraftRoot.'/logs/latest.log';
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

function tailService(): LogTailService
{
    return app(LogTailService::class);
}

/*
|--------------------------------------------------------------------------
| Basic ingestion
|--------------------------------------------------------------------------
*/

it('reports unavailable when the log file does not exist yet', function () {
    $outcome = tailService()->tail();

    expect($outcome->available)->toBeFalse()
        ->and($outcome->reason)->not->toBeNull();

    expect(ConsoleEntry::query()->count())->toBe(0);
});

it('ingests new complete lines into bounded ConsoleEntry rows', function () {
    file_put_contents($this->logPath, "[10:00:00 INFO]: Steve joined the game\n[10:00:05 INFO]: <Steve> hi\n");

    $outcome = tailService()->tail();

    expect($outcome->available)->toBeTrue()
        ->and($outcome->linesProcessed)->toBe(2)
        ->and(ConsoleEntry::query()->count())->toBe(2);

    expect(ConsoleEntry::query()->orderBy('id')->pluck('line')->all())->toBe([
        '[10:00:00 INFO]: Steve joined the game',
        '[10:00:05 INFO]: <Steve> hi',
    ]);
});

it('does not reprocess already-tailed content on a second call with no new data', function () {
    file_put_contents($this->logPath, "[10:00:00 INFO]: Steve joined the game\n");
    tailService()->tail();

    $second = tailService()->tail();

    expect($second->linesProcessed)->toBe(0)
        ->and(ConsoleEntry::query()->count())->toBe(1);
});

it('picks up newly appended lines on a subsequent call', function () {
    file_put_contents($this->logPath, "[10:00:00 INFO]: Steve joined the game\n");
    tailService()->tail();

    file_put_contents($this->logPath, "[10:00:05 INFO]: Steve left the game\n", FILE_APPEND);
    $second = tailService()->tail();

    expect($second->linesProcessed)->toBe(1)
        ->and(ConsoleEntry::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Never split/duplicate a line still being written
|--------------------------------------------------------------------------
*/

it('does not emit a trailing line that has no newline yet, and emits it whole once completed', function () {
    file_put_contents($this->logPath, "[10:00:00 INFO]: Steve joined the game\n[10:00:05 INFO]: Steve was kicked for floating too long");

    $first = tailService()->tail();
    expect($first->linesProcessed)->toBe(1)
        ->and(ConsoleEntry::query()->count())->toBe(1);

    // Append the rest of the second line PLUS its terminator.
    file_put_contents($this->logPath, "!\n", FILE_APPEND);

    $second = tailService()->tail();
    expect($second->linesProcessed)->toBe(1)
        ->and(ConsoleEntry::query()->count())->toBe(2);

    expect(ConsoleEntry::query()->orderBy('id')->pluck('line')->last())
        ->toBe('[10:00:05 INFO]: Steve was kicked for floating too long!');
});

/*
|--------------------------------------------------------------------------
| Rotation (new inode) and truncation (same inode, shrunk)
|--------------------------------------------------------------------------
*/

it('resets to offset 0 and processes only new content when the log file is rotated to a new inode', function () {
    file_put_contents($this->logPath, "[10:00:00 INFO]: old line one\n[10:00:01 INFO]: old line two\n");
    $first = tailService()->tail();
    expect($first->linesProcessed)->toBe(2);

    $oldInode = fileinode($this->logPath);
    $oldOffset = strlen(file_get_contents($this->logPath));

    // Simulate rotation: move the old file aside, create a brand new file
    // at the same path (a fresh inode). Deliberately padded LONGER than
    // the old file's total size, so this test can only pass via the
    // inode check — the independent "same inode but shrunk" truncation
    // check would NOT fire here (the new file is bigger, not smaller,
    // than the old offset), isolating exactly which guard is load-bearing.
    rename($this->logPath, $this->logPath.'.1');
    $freshLine = '[10:05:00 INFO]: '.str_pad('fresh line after rotation', $oldOffset + 50, '-');
    expect(strlen($freshLine) + 1)->toBeGreaterThan($oldOffset);
    file_put_contents($this->logPath, $freshLine."\n");

    expect(fileinode($this->logPath))->not->toBe($oldInode);

    $second = tailService()->tail();

    expect($second->linesProcessed)->toBe(1)
        ->and(ConsoleEntry::query()->count())->toBe(3);

    $lines = ConsoleEntry::query()->orderBy('id')->pluck('line')->all();
    expect($lines)->toContain($freshLine)
        // The old content was already ingested exactly once before
        // rotation — it must not be reprocessed from the renamed file.
        ->and(array_count_values($lines)['[10:00:00 INFO]: old line one'] ?? 0)->toBe(1);
});

it('characterizes the disclosed rotation-straggler window: bytes appended to the OLD inode after the last tail() but before rotation are not recovered', function () {
    // This is NOT a bug being fixed — it pins the documented, accepted V1
    // trade-off (see LogTailService's class docblock and
    // docs/architecture/decisions.md, "Task 11 Fix — Rotation-Straggler
    // Window"): perfect no-drop across a real gzip-archiving rotation
    // isn't achievable by a scheduled (non-daemon) tailer, so this test
    // documents exactly what happens instead of leaving it undiscovered.
    file_put_contents($this->logPath, "[10:00:00 INFO]: already tailed before rotation\n");
    $first = tailService()->tail();
    expect($first->linesProcessed)->toBe(1);

    // A "straggler" line is written to the SAME (still-old) inode AFTER
    // the last successful tail() but BEFORE rotation happens — simulating
    // a burst of server output right before a restart triggers rotation.
    file_put_contents($this->logPath, "[10:00:01 INFO]: straggler line written just before rotation\n", FILE_APPEND);

    $oldInode = fileinode($this->logPath);

    // Simulate rotation to a fresh inode at the same path (mirroring real
    // log4j2 behavior, which moves the old file to an archive — a gzip
    // archive with an unpredictable name in production — and starts a
    // brand new logs/latest.log). The straggler line above was NEVER
    // read by this class before the old inode was moved away.
    rename($this->logPath, $this->logPath.'.1');
    file_put_contents($this->logPath, "[10:05:00 INFO]: first line after rotation\n");

    expect(fileinode($this->logPath))->not->toBe($oldInode);

    $second = tailService()->tail();

    $lines = ConsoleEntry::query()->orderBy('id')->pluck('line')->all();

    // The straggler line is genuinely, permanently lost — this is the
    // documented gap, not a duplicate-avoidance quirk.
    expect($lines)->not->toContain('[10:00:01 INFO]: straggler line written just before rotation')
        // Everything else behaves exactly as guaranteed: the pre-rotation
        // content that WAS already tailed stays ingested exactly once,
        // and the new file's own content is ingested in full.
        ->and($lines)->toContain('[10:00:00 INFO]: already tailed before rotation')
        ->and($lines)->toContain('[10:05:00 INFO]: first line after rotation')
        ->and($second->linesProcessed)->toBe(1);
});

it('resets to offset 0 when the same inode is truncated in place, without skipping the new content', function () {
    file_put_contents($this->logPath, "[10:00:00 INFO]: a very long line that will no longer fit after truncation\n");
    $first = tailService()->tail();
    expect($first->linesProcessed)->toBe(1);

    $inodeBefore = fileinode($this->logPath);

    // Truncate in place (same inode, e.g. a copytruncate-style rotation)
    // and write shorter content.
    file_put_contents($this->logPath, "[10:10:00 INFO]: short\n");

    expect(fileinode($this->logPath))->toBe($inodeBefore);

    $second = tailService()->tail();

    expect($second->linesProcessed)->toBe(1)
        ->and(ConsoleEntry::query()->count())->toBe(2);

    expect(ConsoleEntry::query()->orderBy('id')->pluck('line')->last())->toBe('[10:10:00 INFO]: short');
});

/*
|--------------------------------------------------------------------------
| The 256 KiB per-iteration read cap — no missed or duplicated lines
| across however many tail() calls it takes to drain a large backlog.
|--------------------------------------------------------------------------
*/

it('reads at most 256 KiB per call and fully drains a larger backlog over several calls with zero missed or duplicated lines', function () {
    $lineCount = 1500;
    $content = '';

    for ($i = 1; $i <= $lineCount; $i++) {
        // Each line is padded to a fixed width so the total comfortably
        // exceeds the 256 KiB single-call cap (1500 * 200 ~= 293 KiB).
        $content .= sprintf('[10:00:00 INFO]: marker-%s', str_pad((string) $i, 190, 'x')).\PHP_EOL;
    }

    file_put_contents($this->logPath, $content);
    expect(strlen($content))->toBeGreaterThan(LogTailService::MAX_READ_BYTES);

    $first = tailService()->tail();

    // The cap must have genuinely bound the first call: not everything
    // was processed in one shot.
    expect($first->linesProcessed)->toBeGreaterThan(0)
        ->and($first->linesProcessed)->toBeLessThan($lineCount);

    $totalCalls = 1;
    while (ConsoleEntry::query()->count() < $lineCount && $totalCalls < 20) {
        tailService()->tail();
        $totalCalls++;
    }

    expect(ConsoleEntry::query()->count())->toBe($lineCount);

    $markers = ConsoleEntry::query()->orderBy('id')->pluck('line')
        ->map(function (string $line): int {
            $withoutPrefix = str_replace('[10:00:00 INFO]: marker-', '', $line);

            return (int) preg_replace('/x+$/', '', $withoutPrefix);
        })
        ->all();

    // Every marker 1..$lineCount appears EXACTLY once — no duplicates, no
    // gaps — regardless of how many tail() calls it took.
    sort($markers);
    expect($markers)->toBe(range(1, $lineCount));
});

/*
|--------------------------------------------------------------------------
| ANSI/control sanitization and the 16 KiB per-line storage/UI cap
|--------------------------------------------------------------------------
*/

it('strips ANSI escape sequences and control characters before persisting a line', function () {
    $raw = "\x1B[31m[10:00:00 INFO]: colored\x1B[0m error\x07 text\n";
    file_put_contents($this->logPath, $raw);

    tailService()->tail();

    $stored = ConsoleEntry::query()->sole()->line;

    expect($stored)->not->toContain("\x1B")
        ->and($stored)->not->toContain("\x07")
        ->and($stored)->toBe('[10:00:00 INFO]: colored error text');
});

it('truncates a single line exceeding 16 KiB before storing or broadcasting it', function () {
    $huge = '[10:00:00 INFO]: '.str_repeat('a', 20_000);
    file_put_contents($this->logPath, $huge."\n");

    Event::fake([ConsoleEntryReceived::class]);
    tailService()->tail();

    $stored = ConsoleEntry::query()->sole()->line;

    expect(strlen($stored))->toBeLessThanOrEqual(LogTailService::MAX_ENTRY_BYTES)
        ->and($stored)->toEndWith('... [truncated]');

    Event::assertDispatched(ConsoleEntryReceived::class, function (ConsoleEntryReceived $event) {
        return strlen($event->line) <= LogTailService::MAX_ENTRY_BYTES;
    });
});

/*
|--------------------------------------------------------------------------
| Bounded recent buffer
|--------------------------------------------------------------------------
*/

it('keeps console_entries trimmed to its most recent rows', function () {
    $now = now();
    $seed = [];

    for ($i = 1; $i <= LogTailService::MAX_CONSOLE_ENTRIES; $i++) {
        $seed[] = ['line' => "seed-{$i}", 'occurred_at' => $now, 'created_at' => $now];
    }

    DB::table('console_entries')->insert($seed);
    expect(ConsoleEntry::query()->count())->toBe(LogTailService::MAX_CONSOLE_ENTRIES);

    file_put_contents($this->logPath, "[10:00:00 INFO]: the newest line\n");
    tailService()->tail();

    expect(ConsoleEntry::query()->count())->toBe(LogTailService::MAX_CONSOLE_ENTRIES)
        ->and(ConsoleEntry::query()->where('line', 'seed-1')->exists())->toBeFalse()
        ->and(ConsoleEntry::query()->where('line', '[10:00:00 INFO]: the newest line')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Realtime broadcast
|--------------------------------------------------------------------------
*/

it('dispatches ConsoleEntryReceived once per processed line, after the DB commit', function () {
    Event::fake([ConsoleEntryReceived::class]);

    file_put_contents($this->logPath, "[10:00:00 INFO]: one\n[10:00:01 INFO]: two\n");
    tailService()->tail();

    Event::assertDispatchedTimes(ConsoleEntryReceived::class, 2);
    Event::assertDispatched(ConsoleEntryReceived::class, fn (ConsoleEntryReceived $e) => $e->line === '[10:00:00 INFO]: one');
});

/*
|--------------------------------------------------------------------------
| Player identity integration
|--------------------------------------------------------------------------
*/

it('derives a Player row from a tailed Floodgate join line', function () {
    file_put_contents($this->logPath, "[12:24:20 INFO]: [floodgate] Floodgate player logged in as .aacarm\n");

    tailService()->tail();

    expect(Player::query()->where('username', '.aacarm')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Crash consistency: the cursor only advances after the batch commits —
| "never drop a line" is prioritized over "never duplicate a line".
|--------------------------------------------------------------------------
*/

it('re-ingests (rather than skips) the same batch if the cursor was not advanced after a successful commit — the documented at-least-once trade-off', function () {
    file_put_contents($this->logPath, "[10:00:00 INFO]: Steve joined the game\n");

    tailService()->tail();
    expect(ConsoleEntry::query()->count())->toBe(1);

    // Simulate "the process crashed after the DB transaction committed but
    // before the cursor file was persisted" by rolling the cursor file
    // back to its pre-tail (offset 0) state directly.
    $cursorFile = glob($this->dataRoot.'/log-cursors/*.json')[0];
    file_put_contents($cursorFile, json_encode(['inode' => fileinode($this->logPath), 'offset' => 0]));

    tailService()->tail();

    // The same line was re-ingested (duplicated), not silently skipped —
    // proving data is never lost across a crash at that boundary, even
    // though it can be duplicated.
    expect(ConsoleEntry::query()->count())->toBe(2)
        ->and(ConsoleEntry::query()->pluck('line')->all())->toBe([
            '[10:00:00 INFO]: Steve joined the game',
            '[10:00:00 INFO]: Steve joined the game',
        ]);
});
