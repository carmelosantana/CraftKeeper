<?php

namespace App\Config;

/**
 * One requested edit to a single dotted path (or, for the flat Properties
 * format, a literal key — see PropertiesAdapter) inside a config file.
 * Immutable and format-agnostic: ConfigFormatAdapter::applyChanges()
 * interprets `$path` against whatever nesting convention its own format
 * uses.
 *
 * Scheduling note: the V1 plan's Stable Interfaces list `app/Config/
 * ConfigChange.php` under Task 8's files, but Task 7's own brief test
 * (`ConfigChange::replace('allow-flight', true)`) already depends on it.
 * That inconsistency is resolved here, in Task 7: ConfigChange is created
 * now as a small, dependency-free value object so Task 8's
 * ConfigChangeService/ConfigChangeRequest can be built on top of it
 * without circling back to modify Task 7's adapters. See
 * docs/architecture/decisions.md, Task 7 section, for the recorded
 * rationale.
 */
final readonly class ConfigChange
{
    private function __construct(
        public ConfigChangeKind $kind,
        public string $path,
        public mixed $value,
    ) {}

    public static function replace(string $path, mixed $value): self
    {
        return new self(ConfigChangeKind::Replace, $path, $value);
    }

    public static function add(string $path, mixed $value): self
    {
        return new self(ConfigChangeKind::Add, $path, $value);
    }

    public static function remove(string $path): self
    {
        return new self(ConfigChangeKind::Remove, $path, null);
    }
}
