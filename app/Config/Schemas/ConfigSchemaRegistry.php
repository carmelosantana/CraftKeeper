<?php

namespace App\Config\Schemas;

use App\Filesystem\MinecraftPath;
use RuntimeException;

/**
 * Loads every recognized schema from resources/schemas/config/*.json and
 * resolves which one (if any) applies to a given Minecraft-relative path,
 * using the same filename/directory conventions
 * App\Config\ConfigDiscoveryService::classify() already uses to decide
 * provenance — a file only ever gets a schema here if discovery would
 * also have marked it `recognized: true`.
 */
final class ConfigSchemaRegistry
{
    /** @var array<string, ConfigSchema> keyed by schema id */
    private array $schemas;

    /** @var list<string> */
    private const RECOGNIZED_PAPER_FILES = [
        'paper-global.yml', 'paper-world-defaults.yml', 'bukkit.yml',
        'spigot.yml', 'commands.yml', 'permissions.yml', 'help.yml',
    ];

    public function __construct(?string $schemaDirectory = null)
    {
        $directory = $schemaDirectory ?? resource_path('schemas/config');
        $this->schemas = self::loadAll($directory);
    }

    /**
     * @return list<ConfigSchema>
     */
    public function all(): array
    {
        return array_values($this->schemas);
    }

    public function get(string $id): ?ConfigSchema
    {
        return $this->schemas[$id] ?? null;
    }

    /**
     * Resolves the recognized schema for a Minecraft-relative path, or
     * null when the file has no recognized schema (a generic/discovered
     * file — still editable in Structured/Source mode, just without
     * guided-mode field metadata).
     */
    public function forPath(MinecraftPath $path): ?ConfigSchema
    {
        $relative = $path->relativePath;
        $segments = explode('/', $relative);
        $filename = (string) end($segments);

        if (count($segments) === 1 && $filename === 'server.properties') {
            return $this->get('server-properties');
        }

        if ($segments[0] === 'config' && in_array($filename, self::RECOGNIZED_PAPER_FILES, true)) {
            return $this->get('paper-global');
        }

        if ($segments[0] === 'plugins' && count($segments) >= 2 && $filename === 'config.yml') {
            $pluginDir = strtolower($segments[1]);

            if (str_starts_with($pluginDir, 'geyser')) {
                return $this->get('geyser');
            }

            if (str_starts_with($pluginDir, 'floodgate')) {
                return $this->get('floodgate');
            }
        }

        return null;
    }

    /**
     * @return array<string, ConfigSchema>
     */
    private static function loadAll(string $directory): array
    {
        $schemas = [];

        if (! is_dir($directory)) {
            return $schemas;
        }

        $files = glob($directory.'/*.json') ?: [];
        sort($files);

        foreach ($files as $file) {
            $raw = file_get_contents($file);

            if ($raw === false) {
                throw new RuntimeException("Unable to read config schema file [{$file}].");
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                throw new RuntimeException("Config schema file [{$file}] is not valid JSON.");
            }

            $schema = ConfigSchema::fromArray($decoded);
            $schemas[$schema->id] = $schema;
        }

        return $schemas;
    }
}
