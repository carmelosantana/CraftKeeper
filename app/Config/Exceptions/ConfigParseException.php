<?php

namespace App\Config\Exceptions;

use RuntimeException;
use Throwable;

/**
 * CraftKeeper's own wrapper around whatever the underlying parser library
 * (Symfony Yaml, yosymfony/toml, json_decode) threw. ConfigFormatAdapter::
 * parse() throws this — never the raw library exception — so a caller
 * that skips validate() and calls parse() directly on unverified content
 * still never sees a third-party exception type leak out of App\Config.
 * validate() itself never throws at all; it catches parser failures
 * (including this one) and turns them into ValidationResult diagnostics.
 *
 * `$parsedLine`/`$parsedColumn` (deliberately not named `$line`/`$column`)
 * — PHP's own base Exception class already declares a non-readonly
 * `$line` property (the line *this exception was constructed on*, used
 * by getLine()); redeclaring it as readonly via constructor promotion is
 * a fatal "cannot redeclare non-readonly property as readonly" error.
 */
class ConfigParseException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $parsedLine = null, public readonly ?int $parsedColumn = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
