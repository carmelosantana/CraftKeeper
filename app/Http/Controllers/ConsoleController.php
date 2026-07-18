<?php

namespace App\Http\Controllers;

use App\Console\CommandConsequences;
use App\Console\CommandPolicy;
use App\Console\CommandRisk;
use App\Console\Exceptions\CommandNotSafe;
use App\Console\PredefinedSafeActions;
use App\Console\RconCommandService;
use App\Http\Controllers\Concerns\PresentsOperations;
use App\Models\ConsoleEntry;
use App\Models\Operation;
use App\Operations\OperationAuthor;
use App\Operations\OperationRequest;
use App\Operations\OperationService;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use App\Server\ServerStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET/POST /server/console and its command lifecycle — the ONE place a
 * console command travels from typed text to an executed RCON call, for
 * BOTH the free-text CommandComposer and every predefined safe-action
 * button on this page or App\Http\Controllers\ServerController's page.
 *
 * The elevated-command gate (Task 12's own brief, verbatim): compose()
 * (App\Console\CommandPolicy::classify() + App\Console\
 * CommandConsequences::describe()) is a PURE, read-only preview — it
 * never creates an Operation, never touches RCON, and is safe to call on
 * every keypress-triggered "Compose command" click. Only propose() turns
 * that preview into a real, Proposed Operation (still nothing sent to
 * RCON — matches App\Operations\OperationService::propose()'s own
 * contract). Only approve() — a fresh, separate POST, human-only by type
 * at App\Operations\OperationService::approve()'s own type signature — can
 * ever lead to App\Operations\Handlers\RconCommandHandler::execute()
 * actually writing bytes to the transport. There is no code path in this
 * controller that skips straight from compose()/propose() to execute()
 * for an Elevated command.
 *
 * runSafeAction() is the ONLY "lighter path" — App\Console\
 * RconCommandService::runSafeCommand() refuses outright (throws
 * CommandNotSafe, proposes nothing) for anything CommandPolicy does not
 * classify Safe, so this action can never reach RconCommandHandler for an
 * Elevated or unclassified command either.
 *
 * Degraded RCON (Task 12's ambiguity resolution #2): the command controls
 * on this page (compose/propose/predefined actions) are RCON-dependent
 * and are shown disabled, with a reason, when RCON is unavailable — but
 * the console LINE FEED itself (App\Models\ConsoleEntry, tailed by
 * App\Server\LogTailService from logs/latest.log) is file-based and
 * genuinely independent of RCON (Task 11), so it is still returned here
 * regardless of RCON's status. "Console" is one of the three cards Task
 * 12's ambiguity resolution #2 names as RCON-dependent — read as "the
 * ability to act through Console", since the tailed feed itself has no
 * RCON dependency to lose.
 */
class ConsoleController extends Controller
{
    use PresentsOperations;

    private const RECENT_ENTRIES_LIMIT = 200;

    private const COMMAND_HISTORY_LIMIT = 20;

    public function __construct(
        private readonly ServerStatusService $status,
        private readonly CommandPolicy $policy,
        private readonly CommandConsequences $consequences,
        private readonly RconCommandService $commands,
        private readonly OperationService $operations,
    ) {}

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    /**
     * POST /server/console — a partial Inertia reload of the SAME page
     * requesting only `composePreview` (see resources/js/features/console/
     * CommandComposer.tsx's `router.post(..., { only: ['composePreview'] })`).
     * Every other prop below is a Closure, so Inertia's own partial-reload
     * machinery (vendor/inertiajs/inertia-laravel's PropsResolver) never
     * evaluates them for this request — this action is exactly as cheap as
     * computing one classification + one lookup, not a full page rebuild.
     */
    public function compose(Request $request): Response
    {
        $request->validate(['command' => ['required', 'string', 'max:4096']]);

        return $this->render($request);
    }

    /**
     * POST /server/console/propose — turns a composed command into a real
     * Proposed Operation. Still never touches RCON (App\Console\
     * RconCommandService::proposeCommand() -> App\Operations\
     * OperationService::propose()'s own contract) — this is the step
     * that hands the operator a concrete Operation id to approve or
     * reject next.
     */
    public function propose(Request $request): RedirectResponse
    {
        $data = $request->validate(['command' => ['required', 'string', 'max:4096']]);
        $author = OperationAuthor::user($request->user()->getKey());

        // A composed command that normalizes to exactly "stop" is routed
        // to the dedicated OperationType::ServerStop / App\Operations\
        // Handlers\ServerStopHandler flow — its graceful "save-all flush
        // THEN stop" sequence (Task 10) — rather than through the generic
        // App\Console\RconCommandService::proposeCommand(), which would
        // otherwise send the bare "stop" command as-is and skip that
        // safety sequence entirely. This is the "server.stop via
        // propose->human-approve->execute" flow the task brief names
        // alongside "rcon.command" as the two elevated operation types
        // Console composes.
        $operation = $this->isServerStopCommand($data['command'])
            ? $this->operations->propose(OperationRequest::serverStop(), $author)
            : $this->commands->proposeCommand($data['command'], $author);

        return redirect('/server/console?operation='.$operation->id);
    }

    /**
     * POST /server/console/run — the lighter path for a MANUALLY typed
     * command that CommandPolicy::classify() happens to find Safe (e.g.
     * an operator typing "list" into the free-text composer instead of
     * clicking the predefined button). Generalizes runSafeAction() to any
     * text rather than only the fixed PredefinedSafeActions catalog;
     * App\Console\RconCommandService::runSafeCommand() already refuses
     * (CommandNotSafe, proposing nothing) for anything not classified
     * Safe, so this is exactly as safe as the predefined-action route —
     * neither can ever reach an Elevated command.
     */
    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate(['command' => ['required', 'string', 'max:4096']]);

        try {
            $operation = $this->commands->runSafeCommand($data['command'], $request->user());
        } catch (CommandNotSafe $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return redirect('/server/console');
        }

        Inertia::flash('toast', $operation->status === OperationStatus::Succeeded
            ? ['type' => 'success', 'message' => $operation->outcome ?? 'Command executed.']
            : ['type' => 'error', 'message' => $operation->outcome ?? 'The command could not be completed.']);

        return redirect('/server/console');
    }

    /**
     * POST /server/console/actions/{key} — the lighter path for a
     * predefined Safe action (App\Console\PredefinedSafeActions). Still
     * fully audited: App\Console\RconCommandService::runSafeCommand()
     * proposes, self-approves as the real authenticated $request->user(),
     * and executes in one call — see that method's own docblock for why
     * this can never reach an Elevated command.
     */
    public function runSafeAction(Request $request, string $key): RedirectResponse
    {
        $action = PredefinedSafeActions::find($key);

        abort_if($action === null, 404);

        $command = $action['command'];

        if ($action['needsMessage']) {
            $data = $request->validate(['message' => ['required', 'string', 'max:1000']]);
            $command .= ' '.$data['message'];
        }

        try {
            $operation = $this->commands->runSafeCommand($command, $request->user());
        } catch (CommandNotSafe $e) {
            // Defense in depth only: PredefinedSafeActions' own catalog is
            // asserted, in tests, to be entirely Safe — this branch exists
            // so a future edit to that catalog fails loudly (a flashed
            // error) instead of silently sending an unclassified command.
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return redirect('/server/console');
        }

        Inertia::flash('toast', $operation->status === OperationStatus::Succeeded
            ? ['type' => 'success', 'message' => $operation->outcome ?? 'Action completed.']
            : ['type' => 'error', 'message' => $operation->outcome ?? 'The action could not be completed.']);

        return redirect('/server/console');
    }

    /**
     * POST /server/console/operations/{operation}/approve — the ONLY
     * route in this controller that can lead to an Elevated command
     * actually reaching RCON. A fresh, separate POST every single time;
     * nothing in propose()/compose() ever calls this on the operator's
     * behalf.
     */
    public function approve(Request $request, Operation $operation): RedirectResponse
    {
        $this->guardPendingConsoleOperation($operation);

        $this->operations->approve($operation->id, $request->user());
        $executed = $this->operations->execute($operation->id);

        Inertia::flash('toast', $executed->status === OperationStatus::Succeeded
            ? ['type' => 'success', 'message' => $executed->outcome ?? 'Command executed.']
            : ['type' => 'error', 'message' => $executed->outcome ?? 'The command could not be completed.']);

        return redirect('/server/console?operation='.$operation->id);
    }

    public function reject(Request $request, Operation $operation): RedirectResponse
    {
        $this->guardPendingConsoleOperation($operation);

        $reason = (string) $request->input('reason', 'Discarded by operator.');
        $this->operations->reject($operation->id, $request->user(), $reason);

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Command discarded.']);

        return redirect('/server/console');
    }

    private function render(Request $request): Response
    {
        return Inertia::render('server/Console', [
            'rcon' => fn () => $this->rconStatusProp(),
            'logs' => fn () => $this->logStatusProp(),
            'recentEntries' => fn () => $this->recentEntriesProp(),
            'predefinedActions' => fn () => $this->predefinedActionsProp(),
            'commandHistory' => fn () => $this->commandHistoryProp(),
            'pendingOperation' => fn () => $this->pendingOperationProp($request),
            'composePreview' => fn () => $this->composePreviewProp($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rconStatusProp(): array
    {
        $rcon = $this->status->snapshot()->rcon;

        return ['available' => $rcon->available, 'reason' => $rcon->reason];
    }

    /**
     * @return array<string, mixed>
     */
    private function logStatusProp(): array
    {
        $logs = $this->status->snapshot()->logs;

        return ['available' => $logs->available, 'reason' => $logs->reason];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentEntriesProp(): array
    {
        return array_values(ConsoleEntry::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ENTRIES_LIMIT)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (ConsoleEntry $entry) => [
                'id' => $entry->id,
                'line' => $entry->line,
                'occurredAt' => $entry->occurred_at->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function predefinedActionsProp(): array
    {
        return array_map(fn (array $action) => [
            ...$action,
            'consequence' => $this->consequences->describe($action['command']),
        ], PredefinedSafeActions::ALL);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commandHistoryProp(): array
    {
        return array_values(Operation::query()
            ->whereIn('type', [OperationType::RconCommand, OperationType::ServerStop])
            ->latest()
            ->limit(self::COMMAND_HISTORY_LIMIT)
            ->get()
            ->map(fn (Operation $o) => $this->presentOperationSummary($o))
            ->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingOperationProp(Request $request): ?array
    {
        $operationId = $request->query('operation');

        if (! is_string($operationId) || $operationId === '') {
            return null;
        }

        $operation = Operation::query()->find($operationId);

        if ($operation === null || ! in_array($operation->type, [OperationType::RconCommand, OperationType::ServerStop], true)) {
            return null;
        }

        return [
            ...$this->presentOperationSummary($operation),
            'consequence' => $this->consequenceForOperation($operation),
        ];
    }

    /**
     * OperationType::ServerStop's own target is always the literal string
     * "server" (App\Operations\OperationRequest::serverStop()), not a
     * command a lookup keyed by command text could resolve — describe it
     * the same way composing the literal "stop" command would.
     */
    private function consequenceForOperation(Operation $operation): string
    {
        return $operation->type === OperationType::ServerStop
            ? $this->consequences->describe('stop')
            : $this->consequences->describe($operation->target ?? '');
    }

    /**
     * Whether $command, once normalized, is exactly the server-stop
     * command — see propose()'s own docblock for why this routes to a
     * different OperationType than every other composed command.
     */
    private function isServerStopCommand(string $command): bool
    {
        return strtolower($this->policy->normalize($command)) === 'stop';
    }

    /**
     * A pure, read-only classification + consequence preview — creates
     * nothing, sends nothing. Reads `command` from either the query
     * string (a deep link, e.g. Players' "Kick" action pre-filling the
     * composer) or the POST body (an explicit "Compose command" click);
     * Illuminate\Http\Request::input() already merges both.
     *
     * @return array<string, mixed>|null
     */
    private function composePreviewProp(Request $request): ?array
    {
        $command = $request->input('command');

        if (! is_string($command) || trim($command) === '') {
            return null;
        }

        $risk = $this->policy->classify($command);

        return [
            'command' => $command,
            'normalizedCommand' => $this->policy->normalize($command),
            'risk' => $risk->value,
            'requiresApproval' => $risk === CommandRisk::Elevated,
            'consequence' => $this->consequences->describe($command),
        ];
    }

    private function guardPendingConsoleOperation(Operation $operation): void
    {
        if (! in_array($operation->type, [OperationType::RconCommand, OperationType::ServerStop], true)
            || $operation->status !== OperationStatus::Proposed
        ) {
            abort(404);
        }
    }
}
