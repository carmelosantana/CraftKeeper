<?php

namespace App\Server;

/**
 * Which client protocol a player is (or was, for a given historical
 * PlayerEvent) connected with. Bedrock is only ever assigned when the log
 * line itself carries an explicit Floodgate signal ("Floodgate player
 * logged in as ...") — Task 11's ambiguity resolution #4 requires the
 * EXACT identity/platform the line states, never an inferred or fabricated
 * one. Standard vanilla/Paper join, leave, and kick lines carry no
 * platform information of their own; App\Server\LogParser defaults those
 * to Java, since Floodgate always logs its own preceding line for a
 * Bedrock player, making Java the correct default absent that signal
 * (documented judgment call — see docs/architecture/decisions.md).
 */
enum PlayerPlatform: string
{
    case Java = 'java';
    case Bedrock = 'bedrock';
}
