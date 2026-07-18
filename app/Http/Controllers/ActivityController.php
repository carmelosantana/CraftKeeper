<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\PlayerEvent;
use App\Operations\OperationType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /activity — a chronological, filterable union of everything
 * CraftKeeper has actually recorded so far (Task 12's ambiguity
 * resolution #5). Two REAL, populated sources exist as of Task 12:
 * App\Models\Operation (covers config changes, plugin changes, commands,
 * and server restarts — all just different App\Operations\OperationType
 * values) and App\Models\PlayerEvent (join/leave/kick/chat). Three more
 * categories the brief names — AI proposals, API calls, MCP calls —
 * appeared as selectable filter values from Task 12 onward but produced
 * no items until Tasks 16/17/18 shipped the AI, API, and MCP actors
 * (App\Operations\OperationAuthor::ai()/OperationAuthor::user($id, 'api')/
 * OperationAuthor::mcp()) and the whole-branch fix pass wired these three
 * filters to real predicates on Operation::author_origin (see
 * ORIGIN_SOURCE_MAP below). Every operation now has a real, meaningful
 * author_origin, so these three filters return real operations rather
 * than a silently empty feed.
 *
 * "Never render a secret": every summary string here is built ONLY from
 * fields already proven safe elsewhere — Operation::target (already
 * display-redacted for rcon.command by App\Console\RconCommandService,
 * Task 10) and Operation::outcome (a handler-authored, templated message —
 * see App\Operations\Handlers\RconCommandHandler's own docblock on why its
 * message is never the server's raw response body, and the whole-branch
 * fix pass's own choke point in App\Operations\OperationService, which
 * redacts any known secret VALUE out of outcome before it is ever
 * persisted). PlayerEvent::message (chat text / a kick reason) was never
 * secret-shaped to begin with — Task 11 already surfaces it unredacted,
 * and this controller does not change that.
 */
class ActivityController extends Controller
{
    private const LIMIT = 200;

    /** @var array<string, OperationType[]> */
    private const OPERATION_SOURCE_MAP = [
        'config' => [OperationType::ConfigApply, OperationType::ConfigRestore],
        'plugin' => [
            OperationType::PluginInstall, OperationType::PluginUpdate, OperationType::PluginDisable,
            OperationType::PluginRemove, OperationType::PluginRollback,
        ],
        'command' => [OperationType::RconCommand],
        'server-restart' => [OperationType::ServerStop],
    ];

    /**
     * The three actor-provenance filters — orthogonal to
     * OPERATION_SOURCE_MAP's type-based buckets above: any operation type
     * (config/plugin/command/server-restart) can, in principle, have been
     * authored by a non-human actor. Keyed by the exact
     * Operation::author_origin value App\Operations\OperationAuthor's
     * factory methods actually persist by default —
     * OperationAuthor::ai() -> 'ai', OperationAuthor::mcp() -> 'mcp', and
     * (since the whole-branch fix pass) every /api/v1 controller's
     * OperationAuthor::user($id, 'api') -> 'api'. A human acting through
     * the web UI (origin 'web') or CraftKeeper itself (origin 'system')
     * never matches any of these three.
     *
     * @var array<string, string> source => author_origin
     */
    private const ORIGIN_SOURCE_MAP = [
        'ai-proposal' => 'ai',
        'api-call' => 'api',
        'mcp-call' => 'mcp',
    ];

    /** @var list<string> */
    private const ALL_SOURCES = ['config', 'plugin', 'command', 'server-restart', 'player', 'ai-proposal', 'api-call', 'mcp-call'];

    public function index(Request $request): Response
    {
        $source = $this->nullableString($request->query('source'));
        $status = $this->nullableString($request->query('status'));
        $q = $this->nullableString($request->query('q'));
        $from = $this->nullableString($request->query('from'));
        $to = $this->nullableString($request->query('to'));

        $rows = [...$this->operationItems($source), ...$this->playerItems($source)];

        if ($status !== null) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['status'] === $status));
        }

        if ($q !== null) {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, fn (array $row) => str_contains(mb_strtolower((string) $row['summary']), $needle)));
        }

        if ($from !== null) {
            $bound = $this->normalizeTimestamp($from);
            $rows = array_values(array_filter($rows, fn (array $row) => $row['timestamp'] !== null && $row['timestamp'] >= $bound));
        }

        if ($to !== null) {
            $bound = $this->normalizeTimestamp($to);
            $rows = array_values(array_filter($rows, fn (array $row) => $row['timestamp'] !== null && $row['timestamp'] <= $bound));
        }

        usort($rows, fn (array $a, array $b) => ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? ''));

        $items = $rows;

        return Inertia::render('Activity', [
            'filters' => ['source' => $source, 'status' => $status, 'q' => $q, 'from' => $from, 'to' => $to],
            'sources' => self::ALL_SOURCES,
            'items' => $items,
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
     * @return list<array<string, mixed>>
     */
    private function operationItems(?string $source): array
    {
        // The three actor-provenance filters are a predicate on
        // author_origin, not on type — an ai-/api-/mcp-authored operation
        // can be any OperationType, so this branch queries across ALL
        // types rather than through OPERATION_SOURCE_MAP.
        if ($source !== null && array_key_exists($source, self::ORIGIN_SOURCE_MAP)) {
            return array_values(Operation::query()
                ->where('author_origin', self::ORIGIN_SOURCE_MAP[$source])
                ->latest()
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Operation $operation) => $this->operationRow($operation))
                ->all());
        }

        $types = $source !== null
            ? (self::OPERATION_SOURCE_MAP[$source] ?? [])
            : array_merge(...array_values(self::OPERATION_SOURCE_MAP));

        if ($types === []) {
            return [];
        }

        return array_values(Operation::query()
            ->whereIn('type', $types)
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Operation $operation) => $this->operationRow($operation))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function operationRow(Operation $operation): array
    {
        return [
            'id' => 'operation:'.$operation->id,
            'source' => $this->resolveSource($operation),
            'actor' => [
                'type' => $operation->author_type->value,
                'id' => $operation->author_id,
                'origin' => $operation->author_origin,
            ],
            'timestamp' => $operation->created_at?->toIso8601String(),
            'status' => $operation->status->value,
            'summary' => $this->summarizeOperation($operation),
            'correlationId' => $operation->correlation_id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function playerItems(?string $source): array
    {
        if ($source !== null && $source !== 'player') {
            return [];
        }

        return array_values(PlayerEvent::query()
            ->with('player')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (PlayerEvent $event) => [
                'id' => 'player_event:'.$event->id,
                'source' => 'player',
                'actor' => [
                    'type' => 'player',
                    'id' => $event->player->username,
                    'origin' => 'server',
                ],
                'timestamp' => $event->occurred_at->toIso8601String(),
                // PlayerEvent has no operation-style lifecycle status; its
                // `kind` (join/leave/kick/chat) is the closest analog and
                // fills the same "status" slot every other source uses.
                'status' => $event->kind->value,
                'summary' => $this->summarizePlayerEvent($event),
                'correlationId' => null,
            ])
            ->all());
    }

    private function sourceForType(OperationType $type): string
    {
        foreach (self::OPERATION_SOURCE_MAP as $source => $types) {
            if (in_array($type, $types, true)) {
                return $source;
            }
        }

        return 'command';
    }

    /**
     * The "source" badge an operation row displays. Actor provenance wins
     * over the type-based bucket: an ai-/api-/mcp-authored operation
     * displays as its origin ('ai-proposal'/'api-call'/'mcp-call')
     * regardless of whether it's a config/plugin/command/server-restart
     * type underneath, since that provenance is the more specific and
     * more interesting fact about the row. A web- or system-authored
     * operation (the vast majority) falls through to the existing
     * type-based bucket exactly as before this fix.
     */
    private function resolveSource(Operation $operation): string
    {
        $origin = array_flip(self::ORIGIN_SOURCE_MAP)[$operation->author_origin] ?? null;

        return $origin ?? $this->sourceForType($operation->type);
    }

    private function summarizeOperation(Operation $operation): string
    {
        $base = match ($this->sourceForType($operation->type)) {
            'config' => "Configuration change to {$operation->target}",
            'plugin' => "{$operation->type->value} for {$operation->target}",
            'command' => "Console command: {$operation->target}",
            'server-restart' => 'Server stop',
            default => $operation->type->value,
        };

        return $operation->outcome !== null ? "{$base} — {$operation->outcome}" : $base;
    }

    private function summarizePlayerEvent(PlayerEvent $event): string
    {
        $username = $event->player->username;

        return match ($event->kind->value) {
            'join' => "{$username} joined the game",
            'leave' => "{$username} left the game",
            'kick' => $event->message !== null ? "{$username} was kicked: {$event->message}" : "{$username} was kicked",
            'chat' => "{$username}: {$event->message}",
            default => "{$username} — {$event->kind->value}",
        };
    }
}
