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
 * resolution #5). Two REAL, populated sources exist as of this task:
 * App\Models\Operation (covers config changes, plugin changes, commands,
 * and server restarts — all just different App\Operations\OperationType
 * values) and App\Models\PlayerEvent (join/leave/kick/chat). Three more
 * categories the brief names — AI proposals, API calls, MCP calls — have
 * no backing table yet (Tasks 16/19+); they still appear as selectable
 * filter values (so the filter UI's vocabulary is complete and stable)
 * but never produce an item, which is the honest state rather than
 * fabricating placeholder rows.
 *
 * "Never render a secret": every summary string here is built ONLY from
 * fields already proven safe elsewhere — Operation::target (already
 * display-redacted for rcon.command by App\Console\RconCommandService,
 * Task 10) and Operation::outcome (a handler-authored, templated message —
 * see App\Operations\Handlers\RconCommandHandler's own docblock on why its
 * message is never the server's raw response body). PlayerEvent::message
 * (chat text / a kick reason) was never secret-shaped to begin with — Task
 * 11 already surfaces it unredacted, and this controller does not change
 * that.
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
            ->map(fn (Operation $operation) => [
                'id' => 'operation:'.$operation->id,
                'source' => $this->sourceForType($operation->type),
                'actor' => [
                    'type' => $operation->author_type->value,
                    'id' => $operation->author_id,
                    'origin' => $operation->author_origin,
                ],
                'timestamp' => $operation->created_at?->toIso8601String(),
                'status' => $operation->status->value,
                'summary' => $this->summarizeOperation($operation),
                'correlationId' => $operation->correlation_id,
            ])
            ->all());
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
