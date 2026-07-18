<?php

use App\Catalog\CatalogSchemaValidator;

/**
 * Locks the shared catalog contract (resources/catalog/plugin-catalog.schema.json)
 * that carmelosantana/minecraft-plugin-catalog and
 * App\Catalog\Sources\CraftKeeperCatalogSource both read — see
 * docs/architecture/plugin-catalog.md. Every fixture here is a full,
 * minimal catalog document (not a bare release fragment) so validation
 * exercises the real top-level schema, not an ad-hoc sub-schema.
 *
 * "The others are handled as their category, not crashes" (brief step 1)
 * means: invalid-hash and missing-version are schema-INVALID (rejected
 * with a specific, attributable error — never a PHP exception), while
 * withdrawn is schema-VALID (a withdrawn release is still a
 * well-formed, immutable-by-checksum release; App\Catalog\Sources\
 * CraftKeeperCatalogSource is the one that treats "withdrawn" as its
 * own semantic category — see CraftKeeperCatalogSourceTest).
 */
beforeEach(function () {
    $this->validator = new CatalogSchemaValidator;
});

function catalogFixture(string $name): array
{
    $path = base_path("tests/fixtures/catalog/schema/{$name}.json");

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('accepts a fully valid catalog document', function () {
    $result = $this->validator->validate(catalogFixture('valid'));

    expect($result->isValid)->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('rejects a release whose sha256 is not a valid 64-char hex digest', function () {
    $result = $this->validator->validate(catalogFixture('invalid-hash'));

    expect($result->isValid)->toBeFalse()
        ->and($result->errors)->not->toBeEmpty();

    $joined = implode(' | ', $result->errors);
    expect($joined)->toContain('sha256');
});

it('rejects a release missing the required version field', function () {
    $result = $this->validator->validate(catalogFixture('missing-version'));

    expect($result->isValid)->toBeFalse();

    $joined = implode(' | ', $result->errors);
    expect($joined)->toContain('version');
});

it('accepts a withdrawn release as schema-valid (withdrawal is a semantic, not structural, concern)', function () {
    $result = $this->validator->validate(catalogFixture('withdrawn'));

    expect($result->isValid)->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('rejects a document missing the required top-level catalogVersion', function () {
    $document = catalogFixture('valid');
    unset($document['catalogVersion']);

    $result = $this->validator->validate($document);

    expect($result->isValid)->toBeFalse();
});

it('rejects a plugin missing sourceRepository', function () {
    $document = catalogFixture('valid');
    unset($document['plugins'][0]['sourceRepository']);

    $result = $this->validator->validate($document);

    expect($result->isValid)->toBeFalse();
});

it('accepts a release with no signature at all — signature is optional', function () {
    $document = catalogFixture('valid');
    unset($document['plugins'][0]['releases'][0]['signature']);

    $result = $this->validator->validate($document);

    expect($result->isValid)->toBeTrue();
});

it('rejects a signature object that is present but missing a required field (keyUrl)', function () {
    $document = catalogFixture('valid');
    unset($document['plugins'][0]['releases'][0]['signature']['keyUrl']);

    $result = $this->validator->validate($document);

    expect($result->isValid)->toBeFalse();

    $joined = implode(' | ', $result->errors);
    expect($joined)->toContain('signature');
});
