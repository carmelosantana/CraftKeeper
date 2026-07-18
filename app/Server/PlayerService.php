<?php

namespace App\Server;

use App\Models\Player;
use App\Models\PlayerEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Turns parsed App\Server\LogEvent objects (join/leave/kick/chat, from
 * App\Server\LogParser) into persisted App\Models\Player identities and
 * App\Models\PlayerEvent history rows. This is the one place a Player row
 * is ever created or updated — identity is keyed by the exact username
 * string observed in console output, never a looked-up or fabricated
 * UUID (Task 11's ambiguity resolution #4).
 *
 * record() is entirely independent of RCON/App\Console\RconClient — it
 * only ever consumes already-parsed log events, so it keeps working
 * exactly the same whether or not RCON is currently reachable (Task 11's
 * ambiguity resolution #5: file-based observation stays usable on its
 * own).
 */
final class PlayerService
{
    /**
     * @param  list<LogEvent>  $events
     */
    public function record(array $events, CarbonInterface $occurredAt): void
    {
        foreach ($events as $event) {
            if ($event->kind === LogEventKind::Unknown || $event->player === null) {
                continue;
            }

            $player = $this->findOrCreatePlayer($event->player, $event->platform, $occurredAt);

            PlayerEvent::query()->create([
                'player_id' => $player->id,
                'kind' => $event->kind,
                'platform' => $event->platform,
                'message' => $event->message,
                'raw_line' => $event->raw,
                'occurred_at' => $occurredAt,
            ]);
        }
    }

    /**
     * The most recent player events, newest first. An empty result here
     * means exactly what it says — no events recorded yet — which is
     * different from "unavailable"; there is no live/RCON-dependent
     * signal in this query for App\Server\ServerStatusService's
     * no-fabricated-zero rule to apply to.
     *
     * @return Collection<int, PlayerEvent>
     */
    public function recentEvents(int $limit = 50): Collection
    {
        return PlayerEvent::query()
            ->with('player')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function findOrCreatePlayer(string $username, ?PlayerPlatform $platform, CarbonInterface $occurredAt): Player
    {
        $player = Player::query()->where('username', $username)->first();

        if (! $player instanceof Player) {
            return Player::query()->create([
                'username' => $username,
                'platform' => $platform ?? PlayerPlatform::Java,
                'first_seen_at' => $occurredAt,
                'last_seen_at' => $occurredAt,
            ]);
        }

        $player->update(array_filter([
            'platform' => $platform,
            'last_seen_at' => $occurredAt,
        ], static fn (mixed $value): bool => $value !== null));

        return $player;
    }
}
