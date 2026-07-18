<?php

namespace App\Plugins;

/**
 * The plan's four compatibility states. `Unknown` is the deliberate,
 * honest default: PluginCompatibilityService::evaluate() only returns
 * `Compatible` when at least one piece of evidence POSITIVELY supports
 * it (a satisfied hard dependency, or an api-version comparison that
 * checks out) — never merely because a JAR has valid, readable metadata
 * or "loads." See that class's docblock.
 */
enum PluginCompatibilityState: string
{
    case Compatible = 'compatible';
    case Incompatible = 'incompatible';
    case Unknown = 'unknown';
    case Warning = 'warning';
}
