<?php

namespace App\Config;

use App\Config\Schemas\ConfigSchema;
use App\Filesystem\MinecraftPath;

/**
 * One parser/validator/patcher for a single Minecraft configuration
 * syntax (Properties, YAML, JSON, TOML). This is the plan's Stable
 * Interface, reproduced exactly:
 *
 *     interface ConfigFormatAdapter
 *     {
 *         public function supports(MinecraftPath $path, string $contents): bool;
 *         public function parse(string $contents): ParsedConfig;
 *         public function validate(string $contents, ?ConfigSchema $schema): ValidationResult;
 *         public function applyChanges(string $contents, array $changes, ?ConfigSchema $schema): string;
 *     }
 *
 * Every implementation must uphold two safety properties documented in
 * detail on the concrete classes:
 *
 * 1. validate() NEVER lets an underlying parser exception (Symfony Yaml,
 *    yosymfony/toml, json_decode) escape — malformed input, invalid
 *    UTF-8, and unsupported constructs (e.g. YAML anchors/aliases) are
 *    always converted into ValidationResult diagnostics.
 * 2. applyChanges() patches the original source bytes in place for every
 *    change it can locate as a scalar leaf, so comments, key ordering,
 *    and blank lines survive untouched. Only a change it cannot locate
 *    this way falls back to a full structural re-serialize; concrete
 *    adapters expose that outcome via an additional (non-interface)
 *    willNormalize() method, since this interface's return type can't
 *    carry a second signal — see docs/architecture/decisions.md.
 */
interface ConfigFormatAdapter
{
    public function supports(MinecraftPath $path, string $contents): bool;

    public function parse(string $contents): ParsedConfig;

    public function validate(string $contents, ?ConfigSchema $schema): ValidationResult;

    /**
     * @param  list<ConfigChange>  $changes
     */
    public function applyChanges(string $contents, array $changes, ?ConfigSchema $schema): string;
}
