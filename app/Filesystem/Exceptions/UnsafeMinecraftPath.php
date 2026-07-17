<?php

namespace App\Filesystem\Exceptions;

use RuntimeException;

/**
 * Thrown whenever a user-, API-, MCP-, or AI-supplied path cannot be proven
 * to resolve inside the canonical Minecraft root — the one exception every
 * caller of App\Filesystem\MinecraftPath::fromUserInput() must expect.
 *
 * This covers every escape vector App\Filesystem\MinecraftPath rejects:
 * absolute paths, embedded NUL bytes, ".."/traversal components, reserved
 * Windows device names, and any path (including one reached through a
 * symlink) whose fully resolved, canonicalized target falls outside the
 * canonical Minecraft root.
 */
class UnsafeMinecraftPath extends RuntimeException
{
    public static function forInput(string $userInput, string $reason): self
    {
        return new self(sprintf(
            'Refusing to resolve Minecraft path [%s]: %s.',
            self::displayable($userInput),
            $reason,
        ));
    }

    /**
     * NUL bytes and other control characters make a raw path unsafe to
     * interpolate into an error message via normal string formatting, so
     * this renders anything outside printable ASCII as an escape sequence.
     */
    private static function displayable(string $userInput): string
    {
        return addcslashes($userInput, "\0..\37\177..\377");
    }
}
