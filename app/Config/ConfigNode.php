<?php

namespace App\Config;

/**
 * One located, decoded scalar leaf in a parsed config file. `$path` is a
 * dotted path ("bedrock.port") for nested structured formats (YAML/JSON/
 * TOML) or the literal property key (which may itself legitimately
 * contain dots, e.g. "rcon.port" in server.properties) for the flat
 * Properties format — see PropertiesAdapter's docblock.
 *
 * ParsedConfig::$nodes is intentionally scoped to LOCATABLE SCALAR
 * LEAVES only (booleans, integers, floats, strings, null) — the values a
 * guided/structured editor can safely present and a byte-patch can
 * safely rewrite. Objects, arrays, and any construct a format adapter
 * cannot confidently re-locate in the source text are represented in
 * ParsedConfig::$data (the full decoded structure) but do not get a node
 * here.
 */
final readonly class ConfigNode
{
    public function __construct(
        public string $path,
        public mixed $value,
        public SourceLocation $location,
    ) {}
}
