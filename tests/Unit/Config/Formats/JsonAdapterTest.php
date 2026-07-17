<?php

use App\Config\ConfigChange;
use App\Config\DiagnosticSeverity;
use App\Config\Formats\JsonAdapter;
use App\Filesystem\MinecraftPath;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->root = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->root]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->root);
});

it('supports .json and rejects everything else', function () {
    $adapter = new JsonAdapter;

    expect($adapter->supports(MinecraftPath::fromUserInput('ops.json'), ''))->toBeTrue()
        ->and($adapter->supports(MinecraftPath::fromUserInput('server.properties'), ''))->toBeFalse();
});

it('parses booleans, integers, nulls, strings, and arrays with the right PHP types', function () {
    $source = <<<'JSON'
    {
        "enabled": true,
        "disabled": false,
        "port": 19132,
        "name": null,
        "motd": "Hello world",
        "tags": ["alpha", "beta"]
    }
    JSON;

    $parsed = (new JsonAdapter)->parse($source);

    expect($parsed->data)->toBe([
        'enabled' => true,
        'disabled' => false,
        'port' => 19132,
        'name' => null,
        'motd' => 'Hello world',
        'tags' => ['alpha', 'beta'],
    ]);
});

it('locates a nested scalar leaf node with a source span', function () {
    $source = "{\n    \"bedrock\": {\n        \"port\": 19132\n    }\n}\n";
    $parsed = (new JsonAdapter)->parse($source);

    $node = $parsed->node('bedrock.port');

    expect($node)->not->toBeNull()
        ->and($node->value)->toBe(19132)
        ->and($node->location->line)->toBe(3);
});

it('re-serializes with two-space indentation and a trailing newline', function () {
    $source = '{"a":1,"b":{"c":2}}';
    $result = (new JsonAdapter)->applyChanges($source, [ConfigChange::replace('a', 2)], null);

    expect($result)->toBe("{\n  \"a\": 2,\n  \"b\": {\n    \"c\": 2\n  }\n}\n");
});

it('always reports willNormalize as true for a non-empty change set', function () {
    $adapter = new JsonAdapter;

    expect($adapter->willNormalize('{"a":1}', [ConfigChange::replace('a', 2)], null))->toBeTrue()
        ->and($adapter->willNormalize('{"a":1}', [], null))->toBeFalse();
});

it('reports a trailing comma as a line-numbered diagnostic instead of throwing', function () {
    $source = "{\n    \"a\": 1,\n}\n";
    $result = (new JsonAdapter)->validate($source, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Error)
        ->and($result->diagnostics[0]->line)->toBe(2);
});

it('reports single-quoted keys as a diagnostic instead of throwing', function () {
    $source = "{'a': 1}";
    $result = (new JsonAdapter)->validate($source, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics[0]->message)->toContain('double-quoted');
});

it('reports invalid UTF-8 as a validation diagnostic instead of throwing', function () {
    $invalid = "{\"motd\": \"Hello \xB1 World\"}";
    $result = (new JsonAdapter)->validate($invalid, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics[0]->message)->toContain('UTF-8');
});

it('validates cleanly for well-formed content with no schema', function () {
    $result = (new JsonAdapter)->validate('{"a": 1}', null);

    expect($result->valid)->toBeTrue()
        ->and($result->diagnostics)->toBe([]);
});

it('never throws a raw JsonException from validate()', function () {
    expect(fn () => (new JsonAdapter)->validate('{not json at all', null))->not->toThrow(Throwable::class);
});
