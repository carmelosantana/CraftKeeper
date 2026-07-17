<?php

use App\Config\ConfigChange;
use App\Config\DiagnosticSeverity;
use App\Config\Formats\Support\DotPath;
use App\Config\Formats\YamlAdapter;
use App\Filesystem\MinecraftPath;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->root = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->root]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->root);
});

it('supports .yml and .yaml but not other extensions', function () {
    $adapter = new YamlAdapter;

    expect($adapter->supports(MinecraftPath::fromUserInput('config.yml'), ''))->toBeTrue()
        ->and($adapter->supports(MinecraftPath::fromUserInput('config.yaml'), ''))->toBeTrue()
        ->and($adapter->supports(MinecraftPath::fromUserInput('server.properties'), ''))->toBeFalse();
});

it('parses booleans, integers, nulls, quoted scalars, and arrays', function () {
    $source = <<<'YAML'
    enabled: true
    disabled: false
    port: 19132
    nickname: ~
    quoted: "hello world"
    single: 'it''s fine'
    tags:
      - alpha
      - beta
    YAML;

    $parsed = (new YamlAdapter)->parse($source);

    expect($parsed->data)->toBe([
        'enabled' => true,
        'disabled' => false,
        'port' => 19132,
        'nickname' => null,
        'quoted' => 'hello world',
        'single' => "it's fine",
        'tags' => ['alpha', 'beta'],
    ]);
});

it('patches a top-level scalar in place without disturbing comments or ordering', function () {
    $source = "# Geyser config\nbedrock:\n  port: 19132\n# trailing note\nmax-players: 20\n";

    $result = (new YamlAdapter)->applyChanges($source, [
        ConfigChange::replace('max-players', 40),
    ], null);

    expect($result)->toBe("# Geyser config\nbedrock:\n  port: 19132\n# trailing note\nmax-players: 40\n");
});

it('patches a nested scalar in place without disturbing comments or ordering', function () {
    $source = "# Geyser config\nbedrock:\n  address: 0.0.0.0\n  port: 19132 # udp\nremote:\n  auth-type: floodgate\n";

    $result = (new YamlAdapter)->applyChanges($source, [
        ConfigChange::replace('bedrock.port', 19133),
    ], null);

    expect($result)->toBe("# Geyser config\nbedrock:\n  address: 0.0.0.0\n  port: 19133 # udp\nremote:\n  auth-type: floodgate\n");
});

it('inserts a separating space when patching a bare null-valued key, instead of gluing the value onto the colon', function () {
    $source = "bedrock:\nmax-players: 20\n";

    $result = (new YamlAdapter)->applyChanges($source, [ConfigChange::replace('bedrock', false)], null);

    expect($result)->toBe("bedrock: false\nmax-players: 20\n");
    expect((new YamlAdapter)->parse($result)->data)->toBe(['bedrock' => false, 'max-players' => 20]);
});

it('preserves CRLF endings when patching a nested scalar', function () {
    $source = "bedrock:\r\n  port: 19132\r\nmax-players: 20\r\n";

    $result = (new YamlAdapter)->applyChanges($source, [
        ConfigChange::replace('bedrock.port', 19133),
    ], null);

    expect($result)->toBe("bedrock:\r\n  port: 19133\r\nmax-players: 20\r\n");
});

it('appends a brand-new top-level key without a full re-serialize', function () {
    $source = "# note\nbedrock:\n  port: 19132\n";

    $adapter = new YamlAdapter;
    $result = $adapter->applyChanges($source, [ConfigChange::add('debug-mode', true)], null);

    expect($result)->toBe("# note\nbedrock:\n  port: 19132\ndebug-mode: true\n")
        ->and($adapter->willNormalize($source, [ConfigChange::add('debug-mode', true)], null))->toBeFalse();
});

it('removes an existing scalar leaf by deleting its whole line', function () {
    $source = "# note\nbedrock:\n  port: 19132\n  address: 0.0.0.0\n";

    $result = (new YamlAdapter)->applyChanges($source, [ConfigChange::remove('bedrock.address')], null);

    expect($result)->toBe("# note\nbedrock:\n  port: 19132\n");
});

it('flags normalization for a change that requires a full re-serialize and actually loses comments on that path', function () {
    $source = "# note\nbedrock:\n  port: 19132\n";
    $adapter = new YamlAdapter;
    $change = [ConfigChange::add('bedrock.new-nested.deep', 'value')];

    expect($adapter->willNormalize($source, $change, null))->toBeTrue();

    $result = $adapter->applyChanges($source, $change, null);

    expect($result)->not->toContain('# note')
        ->and(DotPath::has(Yaml::parse($result), 'bedrock.new-nested.deep'))->toBeTrue();
});

it('flags normalization for a same-path batch that only diverges from patch once the first change is applied', function () {
    // The first change patches "foo" in place (a scalar -> array is still
    // a single-line Inline::dump()), but that rewrite means "foo" is no
    // longer a locatable scalar leaf for the SECOND change on the same
    // path — so applyChanges() really does fall back to a full
    // structural re-serialize for this batch, and willNormalize() must
    // say so. Classifying both changes against the pristine original
    // (the old bug) misses this entirely, since "foo" still reads as a
    // patchable scalar there.
    $source = "# note\nfoo: old\nbar: 1\n";
    $adapter = new YamlAdapter;
    $changes = [
        ConfigChange::replace('foo', ['a', 'b']),
        ConfigChange::replace('foo', 'newvalue'),
    ];

    expect($adapter->willNormalize($source, $changes, null))->toBeTrue();

    $result = $adapter->applyChanges($source, $changes, null);

    expect($result)->not->toContain('# note')
        ->and(Yaml::parse($result))->toBe(['foo' => 'newvalue', 'bar' => 1]);
});

it('rejects YAML anchors with a line-numbered diagnostic instead of expanding them', function () {
    $source = "defaults: &defaults\n  timeout: 30\nserver:\n  <<: *defaults\n";
    $result = (new YamlAdapter)->validate($source, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Error)
        ->and($result->diagnostics[0]->line)->toBe(1);
});

it('rejects a YAML alias reference even without a merge key', function () {
    $source = "base: &base value\nother: *base\n";
    $result = (new YamlAdapter)->validate($source, null);

    expect($result->valid)->toBeFalse();
});

it('reports malformed YAML as a line-numbered diagnostic without throwing', function () {
    $source = "bedrock:\n  port: 19132\n  address 0.0.0.0\n";
    $result = (new YamlAdapter)->validate($source, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics)->not->toBeEmpty()
        ->and($result->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Error)
        ->and($result->diagnostics[0]->line)->not->toBeNull();
});

it('reports invalid UTF-8 as a validation diagnostic instead of throwing', function () {
    $invalid = "motd: \"Hello \xB1 World\"\n";
    $result = (new YamlAdapter)->validate($invalid, null);

    expect($result->valid)->toBeFalse()
        ->and($result->diagnostics[0]->message)->toContain('UTF-8');
});

it('validates cleanly for well-formed content with no schema', function () {
    $result = (new YamlAdapter)->validate("bedrock:\n  port: 19132\n", null);

    expect($result->valid)->toBeTrue()
        ->and($result->diagnostics)->toBe([]);
});

it('never throws a raw Symfony parser exception from validate()', function () {
    $source = "key: [1, 2\n";

    expect(fn () => (new YamlAdapter)->validate($source, null))->not->toThrow(Throwable::class);
});
