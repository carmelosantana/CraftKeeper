<?php

use App\Config\ConfigChange;
use App\Config\DiagnosticSeverity;
use App\Config\Exceptions\InvalidConfigChange;
use App\Config\Formats\TomlAdapter;
use App\Filesystem\MinecraftPath;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->root = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->root]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->root);
});

it('supports .toml and rejects everything else', function () {
    $adapter = new TomlAdapter;

    expect($adapter->supports(MinecraftPath::fromUserInput('config.toml'), ''))->toBeTrue()
        ->and($adapter->supports(MinecraftPath::fromUserInput('config.yml'), ''))->toBeFalse();
});

it('parses booleans, integers, floats, strings, and arrays', function () {
    $source = <<<'TOML'
    enabled = true
    port = 19132
    ratio = 3.14
    name = "hello"
    tags = ["alpha", "beta"]
    TOML;

    $parsed = (new TomlAdapter)->parse($source);

    expect($parsed->data)->toBe([
        'enabled' => true,
        'port' => 19132,
        'ratio' => 3.14,
        'name' => 'hello',
        'tags' => ['alpha', 'beta'],
    ]);
});

it('patches a top-level scalar in place without disturbing comments or ordering', function () {
    $source = "# top comment\nenabled = true\nport = 19132\n";

    $result = (new TomlAdapter)->applyChanges($source, [ConfigChange::replace('port', 25565)], null);

    expect($result)->toBe("# top comment\nenabled = true\nport = 25565\n");
});

it('patches a scalar nested under a table header in place', function () {
    $source = "# Geyser config\n[bedrock]\naddress = \"0.0.0.0\"\nport = 19132 # udp\n\n[remote]\nauth-type = \"floodgate\"\n";

    $result = (new TomlAdapter)->applyChanges($source, [ConfigChange::replace('bedrock.port', 19133)], null);

    expect($result)->toBe("# Geyser config\n[bedrock]\naddress = \"0.0.0.0\"\nport = 19133 # udp\n\n[remote]\nauth-type = \"floodgate\"\n");
});

it('preserves CRLF endings when patching a value', function () {
    $source = "enabled = true\r\nport = 19132\r\n";

    $result = (new TomlAdapter)->applyChanges($source, [ConfigChange::replace('port', 25565)], null);

    expect($result)->toBe("enabled = true\r\nport = 25565\r\n");
});

it('inserts a brand-new top-level key before the first table header, not at end of file', function () {
    $source = "enabled = true\n\n[bedrock]\nport = 19132\n";

    $result = (new TomlAdapter)->applyChanges($source, [ConfigChange::add('debug-mode', false)], null);

    expect($result)->toBe("enabled = true\n\ndebug-mode = false\n[bedrock]\nport = 19132\n");
});

it('removes an existing scalar leaf by deleting its whole line', function () {
    $source = "[bedrock]\naddress = \"0.0.0.0\"\nport = 19132\n";

    $result = (new TomlAdapter)->applyChanges($source, [ConfigChange::remove('bedrock.address')], null);

    expect($result)->toBe("[bedrock]\nport = 19132\n");
});

it('rejects a null value for TOML since the format has no nil type', function () {
    expect(fn () => (new TomlAdapter)->applyChanges("port = 1\n", [
        ConfigChange::replace('port', null),
    ], null))->toThrow(InvalidConfigChange::class);
});

it('flags normalization for a change to an array value', function () {
    $source = "tags = [\"a\", \"b\"]\n";
    $adapter = new TomlAdapter;
    $change = [ConfigChange::replace('tags', ['a', 'b', 'c'])];

    expect($adapter->willNormalize($source, $change, null))->toBeTrue();
});

it('never reports a same-path batch as safe when applying it actually throws', function () {
    // The first change targets an existing scalar "foo", which classify()
    // locates and would naively patch in place — but TOML's single-line
    // patcher (unlike YAML's) can't render an array value at all, so
    // applyChanges() throws while applying the FIRST change, before the
    // second (same-path) change is ever reached. willNormalize() must
    // not have reported "false" (safe, in-place) for this batch, since
    // that would be a silent lie about what applyChanges() actually does.
    $source = "foo = 1\nbar = 2\n";
    $adapter = new TomlAdapter;
    $changes = [
        ConfigChange::replace('foo', ['a', 'b']),
        ConfigChange::replace('foo', 'newvalue'),
    ];

    expect(fn () => $adapter->applyChanges($source, $changes, null))->toThrow(InvalidConfigChange::class);
    expect(fn () => $adapter->willNormalize($source, $changes, null))->toThrow(InvalidConfigChange::class);
});

it('reports duplicate keys as a diagnostic instead of throwing', function () {
    $source = "dupe = false\ndupe = true\n";
    $result = (new TomlAdapter)->validate($source, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Error);
});

it('reports malformed TOML as a diagnostic instead of throwing', function () {
    $source = "= 1\n";
    $result = (new TomlAdapter)->validate($source, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics)->not->toBeEmpty();
});

it('reports invalid UTF-8 as a validation diagnostic instead of throwing', function () {
    $invalid = "motd = \"Hello \xB1 World\"\n";
    $result = (new TomlAdapter)->validate($invalid, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics[0]->message)->toContain('UTF-8');
});

it('validates cleanly for well-formed content with no schema', function () {
    $result = (new TomlAdapter)->validate("enabled = true\nport = 19132\n", null);

    expect($result->valid)->toBeTrue()
        ->and($result->diagnostics)->toBe([]);
});

it('never throws a raw parser exception from validate()', function () {
    expect(fn () => (new TomlAdapter)->validate('[abc = 1', null))->not->toThrow(Throwable::class);
});
