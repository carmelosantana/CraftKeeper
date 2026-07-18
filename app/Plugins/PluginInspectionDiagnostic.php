<?php

namespace App\Plugins;

/**
 * One typed, human-readable finding from JarInspector::inspect() — always
 * the outcome of catching and translating a hostile or malformed input
 * (a parser exception, an oversized entry, a missing file, ...), never a
 * raw exception itself. Mirrors App\Config\ConfigDiagnostic's role for
 * the config-parsing domain.
 */
final readonly class PluginInspectionDiagnostic
{
    public function __construct(
        public PluginInspectionIssue $issue,
        public string $message,
    ) {}
}
