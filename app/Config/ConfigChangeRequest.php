<?php

namespace App\Config;

/**
 * A caller's request to change one config file: which file, what its
 * content was believed to hash to when the caller last read it (the
 * optimistic-concurrency "base" hash), and the field-level edits to apply.
 * Format-agnostic and dependency-free, mirroring App\Operations\
 * OperationRequest's role for the generic operation lifecycle — this is
 * the one shape every editing surface (guided/structured/source UI, REST,
 * MCP, AI) converges on before calling ConfigChangeService::propose(), per
 * the V1 plan ("All modes converge on the same ConfigChangeRequest").
 */
final readonly class ConfigChangeRequest
{
    /**
     * @param  string  $path  A Minecraft-relative path, exactly as accepted by App\Filesystem\MinecraftPath::fromUserInput().
     * @param  string  $baseSha256  The sha256 the caller believes the file currently has — compared against the real current hash by ConfigChangeService::propose(); a mismatch throws App\Config\Exceptions\ConfigConflict rather than overwriting a file that changed outside CraftKeeper.
     * @param  list<ConfigChange>  $changes
     */
    public function __construct(
        public string $path,
        public string $baseSha256,
        public array $changes,
    ) {}
}
