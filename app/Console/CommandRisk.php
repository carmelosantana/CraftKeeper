<?php

namespace App\Console;

/**
 * The result of App\Console\CommandPolicy::classify(): whether a console
 * command is on the small predefined allow-list (Safe) or requires the
 * full propose -> human-approve -> execute path with a fresh approval
 * (Elevated). Default-deny: anything not explicitly recognized as Safe is
 * Elevated — see CommandPolicy's own docblock.
 */
enum CommandRisk: string
{
    case Safe = 'safe';
    case Elevated = 'elevated';
}
