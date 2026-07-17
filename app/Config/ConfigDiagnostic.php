<?php

namespace App\Config;

/**
 * One human-readable finding from ConfigFormatAdapter::validate() —
 * always the outcome of catching and translating an underlying parser
 * exception (or a schema mismatch), never a raw exception itself. `$line`
 * and `$column` are 1-indexed and null only when no meaningful position
 * could be determined (e.g. an empty-file or whole-document problem).
 */
final readonly class ConfigDiagnostic
{
    public function __construct(
        public DiagnosticSeverity $severity,
        public string $message,
        public ?int $line = null,
        public ?int $column = null,
        public ?string $path = null,
    ) {}
}
