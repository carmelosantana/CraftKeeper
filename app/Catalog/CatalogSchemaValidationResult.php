<?php

namespace App\Catalog;

/**
 * The result of CatalogSchemaValidator::validate(): a boolean verdict AND
 * the human-readable reasons behind it, never just the verdict alone —
 * so a caller can report WHY a catalog document (or PluginCatalogContractTest's
 * fixtures) failed, not just that it did.
 */
final readonly class CatalogSchemaValidationResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public bool $isValid,
        public array $errors,
    ) {}
}
