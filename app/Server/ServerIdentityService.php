<?php

namespace App\Server;

use App\Config\Formats\PropertiesAdapter;
use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use Throwable;

/**
 * Reads the server's self-declared identity out of `server.properties`.
 *
 * Separate from App\Server\ServerStatusService on purpose: that class
 * documents that it reads ONLY already-persisted state and never touches
 * the filesystem for its RCON answer, so folding a file read into it would
 * break its own contract. This is configuration, not telemetry — it changes
 * when an operator edits a file, not every 15 seconds.
 *
 * BEST-EFFORT BY DESIGN. Every failure path — an unmounted Minecraft root,
 * a missing or unreadable server.properties, a malformed file, a
 * non-numeric max-players — returns ServerIdentity::unknown() rather than
 * throwing. This is rendered in the application shell on EVERY page, so a
 * server whose volume is temporarily unavailable must degrade to "unknown"
 * rather than 500 the entire panel, including the pages an operator would
 * use to diagnose exactly that.
 */
final class ServerIdentityService
{
    private ?ServerIdentity $memoized = null;

    /**
     * Memoized for the life of the request: the application shell renders
     * on every Inertia response, and several call sites may ask.
     */
    public function identity(): ServerIdentity
    {
        return $this->memoized ??= $this->read();
    }

    private function read(): ServerIdentity
    {
        try {
            $path = MinecraftPath::fromUserInput('server.properties');
            $snapshot = app(MinecraftFilesystem::class)->read($path);
            $parsed = app(PropertiesAdapter::class)->parse($snapshot->contents);
        } catch (Throwable) {
            return ServerIdentity::unknown();
        }

        return new ServerIdentity(
            motd: $this->stringValue($parsed->data, 'motd'),
            maxPlayers: $this->intValue($parsed->data, 'max-players'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stringValue(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intValue(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit(trim($value))) {
            return null;
        }

        $parsed = (int) trim($value);

        return $parsed > 0 ? $parsed : null;
    }
}
