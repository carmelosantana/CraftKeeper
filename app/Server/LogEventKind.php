<?php

namespace App\Server;

/**
 * The structured kind App\Server\LogParser assigns to a parsed console
 * line. Unknown is not an error state — it is the deliberate, never-drop
 * fallback for any line that does not match one of the recognized
 * shapes (server startup banners, plugin chatter, warnings, ...): the
 * line is still retained, verbatim, on the resulting LogEvent's $raw
 * property, just with no structured player/kind information extracted
 * from it.
 */
enum LogEventKind: string
{
    case Join = 'join';
    case Leave = 'leave';
    case Kick = 'kick';
    case Chat = 'chat';
    case Unknown = 'unknown';
}
