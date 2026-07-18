<?php

namespace App\Http\Controllers;

use App\Console\CommandConsequences;
use App\Console\PredefinedSafeActions;
use App\Models\Player;
use App\Server\ServerStatusService;
use App\Server\ServerVersionDetector;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /server, GET /server/players — server detail (connection/RCON
 * status, version, paths, predefined safe actions) and the player roster.
 * Predefined safe actions are only DESCRIBED here (App\Console\
 * CommandConsequences); running one, and every elevated-command control,
 * lives on App\Http\Controllers\ConsoleController — one execution path
 * shared by both pages, per that controller's own docblock.
 */
class ServerController extends Controller
{
    public function __construct(
        private readonly ServerStatusService $status,
        private readonly ServerVersionDetector $version,
        private readonly CommandConsequences $consequences,
    ) {}

    public function index(): Response
    {
        $snapshot = $this->status->snapshot();
        $version = $this->version->detect();

        return Inertia::render('server/Index', [
            'rcon' => [
                'available' => $snapshot->rcon->available,
                'reason' => $snapshot->rcon->reason,
                'playerCount' => $snapshot->rcon->playerCount,
                'sampledAt' => $snapshot->rcon->sampledAt?->toIso8601String(),
            ],
            'logs' => ['available' => $snapshot->logs->available, 'reason' => $snapshot->logs->reason],
            'version' => [
                'known' => $version->known,
                'label' => $version->label,
                'source' => $version->source,
                'reason' => $version->reason,
            ],
            'paths' => [
                'minecraftRoot' => (string) config('craftkeeper.minecraft_root'),
                'logFile' => 'logs/latest.log',
            ],
            'predefinedActions' => $this->predefinedActionsProp(),
        ]);
    }

    public function players(): Response
    {
        $snapshot = $this->status->snapshot();
        $onlineNames = $snapshot->rcon->available ? ($snapshot->rcon->playerNames ?? []) : null;

        $players = Player::query()->orderByDesc('last_seen_at')->limit(200)->get();

        return Inertia::render('server/Players', [
            'rconAvailable' => $snapshot->rcon->available,
            'rconReason' => $snapshot->rcon->reason,
            'players' => $players->map(function (Player $player) use ($onlineNames) {
                return [
                    // The EXACT identity CraftKeeper has ever observed for
                    // this player — the literal username string, never a
                    // looked-up or fabricated Java/Bedrock UUID (Task 11's
                    // ambiguity resolution #4, reused verbatim by Task 12's
                    // own resolution #3). Every action built from this row
                    // must use `username` as-is.
                    'username' => $player->username,
                    'platform' => $player->platform->value,
                    'firstSeenAt' => $player->first_seen_at->toIso8601String(),
                    'lastSeenAt' => $player->last_seen_at->toIso8601String(),
                    // null = "unknown" (RCON unavailable) — never fabricated
                    // to false/offline just because we can't currently ask.
                    'online' => $onlineNames === null ? null : in_array($player->username, $onlineNames, true),
                ];
            })->values(),
        ]);
    }

    /**
     * @return list<array{key: string, label: string, command: string, needsMessage: bool, consequence: string}>
     */
    private function predefinedActionsProp(): array
    {
        return array_map(fn (array $action) => [
            ...$action,
            'consequence' => $this->consequences->describe($action['command']),
        ], PredefinedSafeActions::ALL);
    }
}
