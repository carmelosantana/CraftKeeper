<?php

use App\Config\ConfigChange;
use App\Config\ConfigDiagnostic;
use App\Config\DiagnosticSeverity;
use App\Config\Exceptions\InvalidConfigChange;
use App\Config\Formats\PropertiesAdapter;
use App\Filesystem\MinecraftPath;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->root = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->root]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->root);
});

// The brief's verbatim round-trip test — do not alter the shape.
it('patches one properties value without removing comments or reordering keys', function () {
    $source = "# keep this\nallow-flight=false\nmotd=Hello\n";
    $result = (new PropertiesAdapter)->applyChanges($source, [
        ConfigChange::replace('allow-flight', true),
    ], null);

    expect($result)->toBe("# keep this\nallow-flight=true\nmotd=Hello\n");
});

it('supports files ending in .properties and rejects everything else', function () {
    file_put_contents($this->root.'/server.properties', "a=1\n");
    $path = MinecraftPath::fromUserInput('server.properties');
    $adapter = new PropertiesAdapter;

    expect($adapter->supports($path, 'a=1'))->toBeTrue();

    file_put_contents($this->root.'/config.yml', "a: 1\n");
    $yamlPath = MinecraftPath::fromUserInput('config.yml');

    expect($adapter->supports($yamlPath, 'a: 1'))->toBeFalse();
});

it('parses booleans, integers, nulls, and strings with the right PHP types', function () {
    $source = "flag-true=true\nflag-false=false\ncount=42\nnegative=-3\nempty-value=\nbare-key\nname=hello world\n";
    $parsed = (new PropertiesAdapter)->parse($source);

    expect($parsed->data)->toBe([
        'flag-true' => true,
        'flag-false' => false,
        'count' => 42,
        'negative' => -3,
        'empty-value' => null,
        'bare-key' => null,
        'name' => 'hello world',
    ]);
});

it('preserves quoted scalars as literal string content (properties has no quoting syntax)', function () {
    $source = 'motd="Welcome!"'."\n";
    $parsed = (new PropertiesAdapter)->parse($source);

    expect($parsed->data['motd'])->toBe('"Welcome!"');
});

it('keeps a colon inside a value intact because = is the only delimiter', function () {
    $source = "motd=Hello: World\n";
    $parsed = (new PropertiesAdapter)->parse($source);

    expect($parsed->data['motd'])->toBe('Hello: World');
});

it('resolves duplicate keys to the last occurrence for both read and write', function () {
    $source = "level-name=first\nlevel-name=second\n";
    $adapter = new PropertiesAdapter;
    $parsed = $adapter->parse($source);

    expect($parsed->data['level-name'])->toBe('second');

    $result = $adapter->applyChanges($source, [ConfigChange::replace('level-name', 'third')], null);

    expect($result)->toBe("level-name=first\nlevel-name=third\n");
});

it('removes every occurrence of a duplicate key', function () {
    $source = "# note\nlevel-name=first\nmotd=hi\nlevel-name=second\n";
    $result = (new PropertiesAdapter)->applyChanges($source, [ConfigChange::remove('level-name')], null);

    expect($result)->toBe("# note\nmotd=hi\n");
});

it('appends a new key without disturbing existing content', function () {
    $source = "# keep this\nmotd=Hello\n";
    $result = (new PropertiesAdapter)->applyChanges($source, [ConfigChange::add('allow-flight', true)], null);

    expect($result)->toBe("# keep this\nmotd=Hello\nallow-flight=true\n");
});

it('preserves CRLF line endings when patching a value', function () {
    $source = "# keep this\r\nallow-flight=false\r\nmotd=Hello\r\n";
    $result = (new PropertiesAdapter)->applyChanges($source, [
        ConfigChange::replace('allow-flight', true),
    ], null);

    expect($result)->toBe("# keep this\r\nallow-flight=true\r\nmotd=Hello\r\n");
});

it('applies several changes in one call, each preserving the rest of the file', function () {
    $source = "# keep this\nallow-flight=false\nmax-players=20\nmotd=Hello\n";
    $result = (new PropertiesAdapter)->applyChanges($source, [
        ConfigChange::replace('allow-flight', true),
        ConfigChange::replace('max-players', 40),
    ], null);

    expect($result)->toBe("# keep this\nallow-flight=true\nmax-players=40\nmotd=Hello\n");
});

it('never structurally re-serializes — willNormalize is always false', function () {
    $adapter = new PropertiesAdapter;

    expect($adapter->willNormalize("a=1\n", [ConfigChange::replace('a', 2)], null))->toBeFalse()
        ->and($adapter->willNormalize("a=1\n", [ConfigChange::add('new-key', 'v')], null))->toBeFalse();
});

it('reports invalid UTF-8 as a validation diagnostic instead of throwing', function () {
    $invalidUtf8 = "motd=Hello \xB1 World\n";
    $result = (new PropertiesAdapter)->validate($invalidUtf8, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics)->toHaveCount(1)
        ->and($result->diagnostics[0])->toBeInstanceOf(ConfigDiagnostic::class)
        ->and($result->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Error);
});

it('validates cleanly when the file is well-formed and no schema is given', function () {
    $result = (new PropertiesAdapter)->validate("allow-flight=true\nmotd=Hello\n", null);

    expect($result->valid)->toBeTrue()
        ->and($result->diagnostics)->toBe([]);
});

it('rejects a value containing a newline as an invalid change rather than corrupting the file', function () {
    expect(fn () => (new PropertiesAdapter)->applyChanges("motd=Hello\n", [
        ConfigChange::replace('motd', "line one\nline two"),
    ], null))->toThrow(InvalidConfigChange::class);
});

it('inserts the "=" delimiter when patching a bare key that had none, instead of gluing the value onto the key', function () {
    $source = "bare-key\nmotd=Hello\n";
    $result = (new PropertiesAdapter)->applyChanges($source, [ConfigChange::replace('bare-key', 'value')], null);

    expect($result)->toBe("bare-key=value\nmotd=Hello\n");

    $parsed = (new PropertiesAdapter)->parse($result);

    expect($parsed->data)->toBe(['bare-key' => 'value', 'motd' => 'Hello']);
});

it('patches a key that already has "=" but an empty value without touching the delimiter', function () {
    $source = "level-seed=\nmotd=Hello\n";
    $result = (new PropertiesAdapter)->applyChanges($source, [ConfigChange::replace('level-seed', 'abc123')], null);

    expect($result)->toBe("level-seed=abc123\nmotd=Hello\n");
});
