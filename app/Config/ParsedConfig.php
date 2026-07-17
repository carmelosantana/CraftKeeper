<?php

namespace App\Config;

/**
 * The result of ConfigFormatAdapter::parse(): the fully decoded config
 * structure plus every located scalar leaf's source span.
 */
final readonly class ParsedConfig
{
    /**
     * @param  array<string, mixed>  $data  The full decoded structure (nested arrays for YAML/JSON/TOML; a flat map for Properties).
     * @param  list<ConfigNode>  $nodes  Located, source-mapped scalar leaves — see ConfigNode's docblock for scope.
     */
    public function __construct(
        public array $data,
        public array $nodes,
    ) {}

    public function node(string $path): ?ConfigNode
    {
        foreach ($this->nodes as $node) {
            if ($node->path === $path) {
                return $node;
            }
        }

        return null;
    }
}
