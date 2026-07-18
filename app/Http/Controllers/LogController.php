<?php

namespace App\Http\Controllers;

use App\Models\ConsoleEntry;
use App\Server\LogParser;
use App\Server\ServerStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /server/logs, GET /server/logs/download — a filterable, bounded view
 * over the already-ingested App\Models\ConsoleEntry rows (Task 11's
 * App\Server\LogTailService — sanitized, capped at 2000 rows). This is
 * DELIBERATELY independent of RCON: it never calls App\Server\
 * ServerStatusService's rcon half, only the file-based `logs` half, so an
 * RCON outage never degrades this page (Task 12's ambiguity resolution
 * #2 — "file-based Logs remain usable").
 *
 * "source"/"level"/"player" are derived at READ time, not stored columns:
 * ConsoleEntry only persists the sanitized raw line + timestamp (Task 11).
 * `level` comes from a small envelope regex scoped to this controller
 * (deliberately NOT added to App\Server\LogParser, which has its own
 * tested, narrower contract — join/leave/kick/chat classification, not
 * level extraction); `player` reuses App\Server\LogParser::parse() as the
 * pure, stateless function it already is (its own docblock: "no I/O, no
 * database access" — safe to call read-only, on demand, here). A line
 * with no recognized player (most lines — server startup banners, plugin
 * chatter) simply has `player: null`, not a guess.
 */
class LogController extends Controller
{
    private const MAX_ROWS = 2000;

    private const MAX_RESULT_ROWS = 500;

    private const DEFAULT_CONTEXT = 2;

    private const LEVEL_PATTERN = '/^\[\d{2}:\d{2}:\d{2}(?:\s+([A-Za-z]+)\]|\]\s\[[^\]\/]+\/([A-Za-z]+)\])/';

    public function __construct(
        private readonly ServerStatusService $status,
        private readonly LogParser $parser,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->filtersFromRequest($request);
        $rows = $this->filteredRows($filters);

        return Inertia::render('server/Logs', [
            'logs' => fn () => $this->logStatusProp(),
            'filters' => $filters,
            'levels' => ['INFO', 'WARN', 'ERROR', 'UNKNOWN'],
            'sources' => ['console'],
            // The most RECENT matches, not the oldest — array_slice with a
            // negative length keeps the tail of the (oldest-first) bounded
            // set, which is what "recent logs" means to an operator.
            'entries' => array_slice($rows, -self::MAX_RESULT_ROWS),
            'truncated' => count($rows) > self::MAX_RESULT_ROWS,
            'totalMatched' => count($rows),
        ]);
    }

    public function download(Request $request): HttpResponse
    {
        $filters = $this->filtersFromRequest($request);
        // Bounded like the on-screen view (Task 12's "copy and download of
        // bounded results") — the most recent MAX_RESULT_ROWS matches, not
        // an unbounded export of everything ConsoleEntry has ever kept.
        $rows = array_slice($this->filteredRows($filters), -self::MAX_RESULT_ROWS);

        $text = implode("\n", array_map(
            fn (array $row) => sprintf('[%s] %s', $row['occurredAt'], $row['line']),
            $rows,
        ))."\n";

        return response($text, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="craftkeeper-logs.txt"',
        ]);
    }

    /**
     * @return array{level: ?string, player: ?string, q: ?string, from: ?string, to: ?string, context: int}
     */
    private function filtersFromRequest(Request $request): array
    {
        return [
            'level' => $this->nullableString($request->query('level')),
            'player' => $this->nullableString($request->query('player')),
            'q' => $this->nullableString($request->query('q')),
            'from' => $this->nullableString($request->query('from')),
            'to' => $this->nullableString($request->query('to')),
            'context' => max(0, min(10, (int) $request->query('context', self::DEFAULT_CONTEXT))),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array{level: ?string, player: ?string, q: ?string, from: ?string, to: ?string, context: int}  $filters
     * @return list<array<string, mixed>>
     */
    private function filteredRows(array $filters): array
    {
        $entries = ConsoleEntry::query()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get();

        /** @var list<array<string, mixed>> $decorated */
        $decorated = $entries->map(function (ConsoleEntry $entry) {
            $event = $this->parser->parse([$entry->line])[0];

            return [
                'id' => $entry->id,
                'line' => $entry->line,
                'occurredAt' => $entry->occurred_at->toIso8601String(),
                'level' => $this->levelFromLine($entry->line),
                'player' => $event->player,
                'source' => 'console',
            ];
        })->values()->all();

        $matchedIndices = [];

        foreach ($decorated as $index => $row) {
            if (! $this->rowMatchesNonTextFilters($row, $filters)) {
                continue;
            }

            if ($filters['q'] !== null && ! str_contains(mb_strtolower((string) $row['line']), mb_strtolower($filters['q']))) {
                continue;
            }

            $matchedIndices[] = $index;
        }

        // Context lines only make sense as "grep -C"-style padding around a
        // TEXT search hit — expanding around a level/player/time match too
        // would defeat the point of filtering by those (an operator
        // filtering to WARN lines wants exactly the WARN lines, not every
        // line within two rows of one). Only $filters['q'] triggers
        // expansion; every other filter combination returns exactly its
        // matches, all flagged `matched: true`.
        $expanded = $filters['q'] !== null
            ? $this->expandWithContext($matchedIndices, count($decorated), $filters['context'])
            : $matchedIndices;

        $result = [];

        foreach ($expanded as $index) {
            $result[] = [
                ...$decorated[$index],
                'matched' => in_array($index, $matchedIndices, true),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{level: ?string, player: ?string, q: ?string, from: ?string, to: ?string, context: int}  $filters
     */
    private function rowMatchesNonTextFilters(array $row, array $filters): bool
    {
        if ($filters['level'] !== null && strcasecmp((string) $row['level'], $filters['level']) !== 0) {
            return false;
        }

        if ($filters['player'] !== null && $row['player'] !== $filters['player']) {
            return false;
        }

        if ($filters['from'] !== null && $row['occurredAt'] !== null && $row['occurredAt'] < $this->normalizeTimestamp($filters['from'])) {
            return false;
        }

        if ($filters['to'] !== null && $row['occurredAt'] !== null && $row['occurredAt'] > $this->normalizeTimestamp($filters['to'])) {
            return false;
        }

        return true;
    }

    private function normalizeTimestamp(string $value): string
    {
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * @param  list<int>  $matchedIndices
     * @return list<int>
     */
    private function expandWithContext(array $matchedIndices, int $total, int $context): array
    {
        if ($matchedIndices === []) {
            return [];
        }

        $expanded = [];

        foreach ($matchedIndices as $index) {
            for ($i = max(0, $index - $context); $i <= min($total - 1, $index + $context); $i++) {
                $expanded[$i] = true;
            }
        }

        $indices = array_keys($expanded);
        sort($indices);

        return $indices;
    }

    private function levelFromLine(string $line): string
    {
        if (preg_match(self::LEVEL_PATTERN, $line, $matches) === 1) {
            $level = strtoupper($matches[1] !== '' ? $matches[1] : $matches[2]);

            if (in_array($level, ['INFO', 'WARN', 'ERROR'], true)) {
                return $level;
            }
        }

        return 'UNKNOWN';
    }

    /**
     * @return array<string, mixed>
     */
    private function logStatusProp(): array
    {
        $logs = $this->status->snapshot()->logs;

        return ['available' => $logs->available, 'reason' => $logs->reason];
    }
}
