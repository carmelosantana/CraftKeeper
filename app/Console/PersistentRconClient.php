<?php

namespace App\Console;

/**
 * An RconClient that is expected to HOLD one authenticated connection
 * open across many execute() calls, instead of opening a fresh one per
 * command.
 *
 * This exists as its own type so the one caller that needs that
 * behavior — App\Console\Commands\WatchServerState, the long-running
 * health poll — asks for it explicitly, and so the container binding that
 * supplies a persistently-configured client is separate from the ordinary
 * RconClient binding every other caller resolves. Nothing else should
 * depend on this: user-issued, audited commands are rare and must keep
 * using the connection-per-command default.
 *
 * The reason the distinction is worth a type at all is the operator's
 * log. Minecraft writes two INFO lines for every RCON connection it
 * accepts, so "how many connections" is a user-visible cost, not an
 * implementation detail — see App\Console\MinecraftRconClient's docblock
 * for the measurements.
 *
 * Implementations must make disconnect() safe to call at any time,
 * including when nothing is currently connected and more than once.
 */
interface PersistentRconClient extends RconClient
{
    public function disconnect(): void;
}
