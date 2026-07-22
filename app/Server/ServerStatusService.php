<?php

namespace App\Server;

use App\Filesystem\Exceptions\MinecraftRootUnavailable;
use App\Filesystem\Exceptions\UnsafeMinecraftPath;
use App\Filesystem\MinecraftPath;
use App\Models\ServerSample;

/**
 * Aggregates current server health with PER-SOURCE state (Task 11's
 * ambiguity resolution #5). Reads only already-persisted state
 * (App\Models\ServerSample, written every 15 seconds by App\Console\
 * Commands\WatchServerState) — it never itself talks to RCON —
 * and checks the log file's accessibility directly and independently, so
 * neither source's computation can ever be influenced by the other's
 * outcome.
 *
 * "No fabricated zero": rconStatus() returns RconStatus::unavailable()
 * (playerCount/playerNames both null) whenever there is no sample, the
 * most recent sample reports RCON unreachable, or the most recent sample
 * is too stale to trust as "current" — it NEVER falls back to reporting
 * 0 players just because a number wasn't available.
 */
final class ServerStatusService
{
    /**
     * A sample older than this is not trusted as representing the
     * server's CURRENT state, even if it was itself a successful sample —
     * exactly 3x App\Console\Commands\WatchServerState::POLL_INTERVAL_SECONDS,
     * so a couple of missed ticks don't immediately flip the UI to
     * "unavailable", but a genuinely stalled poller does.
     *
     * Public because that 3:1 ratio is a contract between two classes,
     * not a private detail: moving the poll cadence without moving this
     * makes the dashboard either flicker or lie, so a test pins the
     * relationship between them.
     */
    public const SAMPLE_FRESHNESS_SECONDS = 45;

    public function snapshot(): ServerStatusSnapshot
    {
        return new ServerStatusSnapshot(
            rcon: $this->rconStatus(),
            logs: $this->logStatus(),
        );
    }

    private function rconStatus(): RconStatus
    {
        $sample = ServerSample::query()->latest('sampled_at')->first();

        if (! $sample instanceof ServerSample) {
            return RconStatus::unavailable('No RCON sample has been recorded yet.');
        }

        if (! $sample->rcon_reachable) {
            return RconStatus::unavailable($sample->error_reason ?? 'RCON is unreachable.');
        }

        if (now()->diffInSeconds($sample->sampled_at, absolute: true) > self::SAMPLE_FRESHNESS_SECONDS) {
            return RconStatus::unavailable('The last successful RCON sample is stale.');
        }

        if ($sample->player_count === null) {
            // RCON was reachable, but the sampler could not parse a player
            // count out of the "list" response (App\Server\ServerSampler's
            // own "unrecognized response" case) — this
            // is a known-unknown, never reported as a fabricated
            // "available" with a null count.
            return RconStatus::unavailable($sample->error_reason ?? 'RCON connected, but the player list response was unreadable.');
        }

        return RconStatus::available($sample->player_count, $sample->player_names, $sample->sampled_at);
    }

    private function logStatus(): LogStatus
    {
        try {
            $path = MinecraftPath::fromUserInput(LogTailService::DEFAULT_LOG_PATH);
        } catch (MinecraftRootUnavailable|UnsafeMinecraftPath) {
            return LogStatus::unavailable('The Minecraft root is unavailable.');
        }

        if (! $path->exists) {
            return LogStatus::unavailable('The server log file was not found.');
        }

        if (! is_readable($path->absolutePath)) {
            return LogStatus::unavailable('The server log file is not readable.');
        }

        return LogStatus::available();
    }
}
