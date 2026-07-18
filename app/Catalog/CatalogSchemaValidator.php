<?php

namespace App\Catalog;

use JsonSchema\Validator;

/**
 * Validates a decoded catalog JSON document (an associative array, as
 * produced by json_decode(..., true)) against the shared contract at
 * resources/catalog/plugin-catalog.schema.json — see
 * docs/architecture/plugin-catalog.md for what this contract is and who
 * else consumes it (the independent carmelosantana/minecraft-plugin-catalog
 * repository's own CI validates every contribution against the exact
 * same file).
 *
 * This class is deliberately the ONLY place justinrainbow/json-schema is
 * used. App\Catalog\Sources\CraftKeeperCatalogSource does NOT run every
 * fetched document through this validator at request time — a real
 * catalog can contain a mix of valid and invalid releases (see the
 * "invalid-hash"/"missing-version"/"withdrawn" fixture categories), and
 * whole-document schema validation is all-or-nothing: one bad release
 * would mark the ENTIRE document invalid. CraftKeeperCatalogSource
 * instead normalizes per-release (App\Catalog\Sources\
 * CraftKeeperReleaseNormalizer), independently re-implementing the same
 * required-field/sha256-pattern rules in plain PHP so one malformed
 * release is skipped without discarding the rest of the catalog. This
 * class exists for the CONTRACT itself: PluginCatalogContractTest proves
 * the schema file correctly accepts/rejects each fixture category, and
 * it is also available to the independent catalog repository's own CI
 * (or any future admin "validate before publishing" tooling) as a
 * single reusable entry point.
 */
final class CatalogSchemaValidator
{
    private readonly object $schema;

    public function __construct()
    {
        $path = base_path('resources/catalog/plugin-catalog.schema.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException("Could not read the catalog schema at {$path}.");
        }

        $this->schema = json_decode($contents, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $document  A decoded catalog document (json_decode(..., true) shape).
     */
    public function validate(array $document): CatalogSchemaValidationResult
    {
        // The validator library mutates its subject in place and expects
        // stdClass objects (not associative arrays) to distinguish JSON
        // objects from JSON arrays correctly.
        $subject = json_decode(json_encode($document, flags: JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

        $validator = new Validator;
        $validator->validate($subject, $this->schema);

        if ($validator->isValid()) {
            return new CatalogSchemaValidationResult(true, []);
        }

        $errors = array_values(array_map(
            fn (array $error): string => ($error['property'] !== '' ? $error['property'].': ' : '').$error['message'],
            $validator->getErrors(),
        ));

        return new CatalogSchemaValidationResult(false, $errors);
    }
}
