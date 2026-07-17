<?php

namespace App\Config;

use App\Config\Exceptions\UnsupportedConfigFormat;
use App\Config\Formats\JsonAdapter;
use App\Config\Formats\PropertiesAdapter;
use App\Config\Formats\TomlAdapter;
use App\Config\Formats\YamlAdapter;
use App\Filesystem\FileSnapshot;

/**
 * Resolves the one ConfigFormatAdapter that owns a given discovered
 * file, per the plan's `ConfigFormatRegistry::for(FileSnapshot):
 * ConfigFormatAdapter` contract. Adapters are tried in a fixed order and
 * the first one whose supports() agrees wins — every shipped adapter
 * currently decides supports() purely from the file's extension (which
 * is also exactly what Task 6's ConfigDiscoveryService restricts
 * discovery to: properties/yml/yaml/json/toml), so ordering never
 * actually matters between them today; it is fixed anyway so a future
 * adapter with broader content-sniffing can be inserted predictably.
 */
final class ConfigFormatRegistry
{
    /** @var list<ConfigFormatAdapter> */
    private readonly array $adapters;

    public function __construct(
        PropertiesAdapter $properties,
        YamlAdapter $yaml,
        JsonAdapter $json,
        TomlAdapter $toml,
    ) {
        $this->adapters = [$properties, $yaml, $json, $toml];
    }

    public function for(FileSnapshot $snapshot): ConfigFormatAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($snapshot->path, $snapshot->contents)) {
                return $adapter;
            }
        }

        throw UnsupportedConfigFormat::forPath($snapshot->path);
    }
}
