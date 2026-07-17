<?php

namespace App\Config;

/**
 * The result of ConfigFormatAdapter::validate(). `$valid` is true iff
 * `$diagnostics` contains no Error-severity entry — Warning-severity
 * diagnostics (e.g. a normalization warning, or a schema-recommended-but-
 * not-required field) do not fail validation.
 */
final readonly class ValidationResult
{
    /**
     * @param  list<ConfigDiagnostic>  $diagnostics
     */
    public function __construct(
        public bool $valid,
        public array $diagnostics = [],
    ) {}

    public static function valid(): self
    {
        return new self(true, []);
    }

    /**
     * @param  list<ConfigDiagnostic>  $diagnostics
     */
    public static function invalid(array $diagnostics): self
    {
        return new self(false, $diagnostics);
    }

    /**
     * Builds a result from a mixed bag of diagnostics, deriving $valid
     * from whether any of them is Error severity (Warning-only is still
     * valid).
     *
     * @param  list<ConfigDiagnostic>  $diagnostics
     */
    public static function fromDiagnostics(array $diagnostics): self
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === DiagnosticSeverity::Error) {
                return new self(false, $diagnostics);
            }
        }

        return new self(true, $diagnostics);
    }
}
