<?php

use App\Config\Schemas\ConfigFieldType;
use App\Config\Schemas\ConfigSchemaRegistry;
use App\Filesystem\MinecraftPath;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->root = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->root]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->root);
});

it('loads all four recognized schemas from resources/schemas/config', function () {
    $registry = new ConfigSchemaRegistry;
    $ids = array_map(fn ($schema) => $schema->id, $registry->all());

    expect($ids)->toEqualCanonicalizing(['server-properties', 'paper-global', 'geyser', 'floodgate']);
});

it('every field carries the full required metadata, including the secret flag', function () {
    $registry = new ConfigSchemaRegistry;

    foreach ($registry->all() as $schema) {
        foreach ($schema->fields as $field) {
            expect($field->path)->not->toBe('')
                ->and($field->title)->not->toBe('')
                ->and($field->description)->not->toBe('')
                ->and($field->type)->toBeInstanceOf(ConfigFieldType::class)
                ->and($field->documentationUrl)->toStartWith('https://')
                ->and($field->secret)->toBeBool();
        }
    }
});

it('flags rcon.password as secret in the server-properties schema', function () {
    $schema = (new ConfigSchemaRegistry)->get('server-properties');
    $field = $schema->field('rcon.password');

    expect($field)->not->toBeNull()
        ->and($field->secret)->toBeTrue()
        ->and($field->type)->toBe(ConfigFieldType::String);
});

it('does not flag ordinary fields as secret', function () {
    $schema = (new ConfigSchemaRegistry)->get('server-properties');

    expect($schema->field('motd')->secret)->toBeFalse()
        ->and($schema->field('online-mode')->secret)->toBeFalse();
});

it('covers RCON, flight, whitelist, ports, and online mode in server-properties', function () {
    $schema = (new ConfigSchemaRegistry)->get('server-properties');
    $paths = array_map(fn ($field) => $field->path, $schema->fields);

    expect($paths)->toContain('enable-rcon', 'rcon.port', 'rcon.password', 'allow-flight', 'white-list', 'enforce-whitelist', 'server-port', 'query.port', 'online-mode');
});

it('covers Geyser remote address and auth-type', function () {
    $schema = (new ConfigSchemaRegistry)->get('geyser');
    $paths = array_map(fn ($field) => $field->path, $schema->fields);

    expect($paths)->toContain('remote.address', 'remote.auth-type', 'bedrock.port');
});

it('covers the Floodgate key file path', function () {
    $schema = (new ConfigSchemaRegistry)->get('floodgate');
    $paths = array_map(fn ($field) => $field->path, $schema->fields);

    expect($paths)->toContain('key-file-name');
});

it('resolves server.properties at the Minecraft root to the server-properties schema', function () {
    $registry = new ConfigSchemaRegistry;
    $path = MinecraftPath::fromUserInput('server.properties');

    expect($registry->forPath($path)?->id)->toBe('server-properties');
});

it('resolves config/paper-global.yml to the paper-global schema', function () {
    $registry = new ConfigSchemaRegistry;
    $path = MinecraftPath::fromUserInput('config/paper-global.yml');

    expect($registry->forPath($path)?->id)->toBe('paper-global');
});

it('resolves a Geyser plugin config.yml by conventional folder name', function () {
    $registry = new ConfigSchemaRegistry;
    $path = MinecraftPath::fromUserInput('plugins/Geyser-Spigot/config.yml');

    expect($registry->forPath($path)?->id)->toBe('geyser');
});

it('resolves a Floodgate plugin config.yml by conventional folder name', function () {
    $registry = new ConfigSchemaRegistry;
    $path = MinecraftPath::fromUserInput('plugins/floodgate/config.yml');

    expect($registry->forPath($path)?->id)->toBe('floodgate');
});

it('returns null for a generic/unrecognized file', function () {
    $registry = new ConfigSchemaRegistry;
    $path = MinecraftPath::fromUserInput('plugins/SomeOtherPlugin/config.yml');

    expect($registry->forPath($path))->toBeNull();
});
