<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOperations;
use App\Models\Operation;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use App\Server\PlayerService;
use App\Server\RconStatus;
use App\Server\ServerStatusService;
use App\Server\ServerStatusSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /overview — the operator's landing page. Composes Task 11's
 * App\Server\ServerStatusService (per-source degraded state, never a
 * fabricated zero) and App\Server\PlayerService with Task 5's Operation
 * ledger. No new domain logic beyond "pending restart" derivation and
 * "attention items" synthesis lives here — everything else is presentation
 * over already-existing services.
 */
class OverviewController extends Controller
{
    use PresentsOperations;

    /**
     * Operation types whose restart_impact metadata (Task 8's
     * App\Config\ConfigChangeService) is meaningful for "is a restart
     * pending" — only config mutations carry that field at all.
     */
    private const CONFIG_OPERATION_TYPES = [OperationType::ConfigApply, OperationType::ConfigRestore];

    public function __construct(
        private readonly ServerStatusService $status,
        private readonly PlayerService $players,
    ) {}

    public function index(): Response
    {
        $snapshot = $this->status->snapshot();
        $recentOperations = Operation::query()->latest()->limit(8)->get();
        $recentPlayerEvents = $this->players->recentEvents(8);
        $pendingRestart = $this->pendingRestart();

        return Inertia::render('Overview', [
            'health' => [
                'rcon' => $this->rconStatusProp($snapshot->rcon),
                'logs' => ['available' => $snapshot->logs->available, 'reason' => $snapshot->logs->reason],
            ],
            // Resource telemetry (CPU/memory) was explicitly never built —
            // Task 11's decisions.md: "No metrics/Prometheus/tracing ...
            // was built." Reporting anything here would be exactly the
            // fabrication the brief prohibits; this is always unavailable,
            // honestly, until a future task adds real collection.
            'resources' => ['available' => false, 'reason' => 'Resource metrics are not collected in this version of CraftKeeper.'],
            'pendingRestart' => $pendingRestart,
            'recentOperations' => $recentOperations->map(fn (Operation $o) => $this->presentOperationSummary($o))->values(),
            'recentPlayerActivity' => $recentPlayerEvents->map(fn ($event) => [
                'id' => $event->id,
                'player' => $event->player?->username,
                'platform' => $event->platform?->value,
                'kind' => $event->kind->value,
                'message' => $event->message,
                'occurredAt' => $event->occurred_at->toIso8601String(),
            ])->values(),
            'attentionItems' => $this->attentionItems($snapshot, $pendingRestart, $recentOperations),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rconStatusProp(RconStatus $rcon): array
    {
        return [
            'available' => $rcon->available,
            'reason' => $rcon->reason,
            'playerCount' => $rcon->playerCount,
            'playerNames' => $rcon->playerNames,
            'sampledAt' => $rcon->sampledAt?->toIso8601String(),
        ];
    }

    /**
     * True when the most recent config mutation that required a restart
     * (App\Config\Schemas\RestartImpact::Restart) succeeded AFTER the most
     * recent successful server.stop — i.e. a change is saved but the
     * running server has not been restarted since. There is no explicit
     * "restart completed" event beyond a successful server.stop
     * operation, since CraftKeeper never talks to the Docker socket/
     * process manager directly (App\Operations\Handlers\ServerStopHandler's
     * own docblock) — the container's own restart policy is what actually
     * brings the server back, which this application has no visibility
     * into beyond RCON becoming reachable again.
     */
    private function pendingRestart(): bool
    {
        $lastRestartImpacting = Operation::query()
            ->whereIn('type', self::CONFIG_OPERATION_TYPES)
            ->where('status', OperationStatus::Succeeded)
            ->where('redacted_input->restart_impact', 'restart')
            ->latest('finished_at')
            ->first();

        if (! $lastRestartImpacting instanceof Operation || $lastRestartImpacting->finished_at === null) {
            return false;
        }

        $laterStop = Operation::query()
            ->where('type', OperationType::ServerStop)
            ->where('status', OperationStatus::Succeeded)
            ->where('finished_at', '>', $lastRestartImpacting->finished_at)
            ->exists();

        return ! $laterStop;
    }

    /**
     * @param  Collection<int, Operation>  $recentOperations
     * @return list<array{kind: string, message: string}>
     */
    private function attentionItems(ServerStatusSnapshot $snapshot, bool $pendingRestart, Collection $recentOperations): array
    {
        $items = [];

        if (! $snapshot->rcon->available) {
            $items[] = ['kind' => 'rcon-unavailable', 'message' => 'RCON is unavailable: '.$snapshot->rcon->reason];
        }

        if (! $snapshot->logs->available) {
            $items[] = ['kind' => 'logs-unavailable', 'message' => 'Server logs are unavailable: '.$snapshot->logs->reason];
        }

        if ($pendingRestart) {
            $items[] = ['kind' => 'pending-restart', 'message' => 'A saved change needs a server restart to take effect.'];
        }

        $lastFailed = $recentOperations->firstWhere('status', OperationStatus::Failed);

        if ($lastFailed instanceof Operation) {
            $items[] = [
                'kind' => 'operation-failed',
                'message' => sprintf('The last "%s" operation failed: %s', $lastFailed->type->value, $lastFailed->outcome ?? 'no further detail was recorded.'),
            ];
        }

        return $items;
    }
}
