<?php

use App\Config\ConfigFormatRegistry;
use App\Config\Exceptions\UnsupportedConfigFormat;
use App\Config\Formats\JsonAdapter;
use App\Config\Formats\PropertiesAdapter;
use App\Config\Formats\TomlAdapter;
use App\Config\Formats\YamlAdapter;
use App\Filesystem\FileSnapshot;
use App\Filesystem\MinecraftPath;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->root = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->root]);

    $this->registry = new ConfigFormatRegistry(new PropertiesAdapter, new YamlAdapter, new JsonAdapter, new TomlAdapter);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->root);
});

function ck_snapshot(string $relativePath, string $contents = ''): FileSnapshot
{
    return new FileSnapshot(MinecraftPath::fromUserInput($relativePath), $contents, hash('sha256', $contents), 0644, time());
}

it('resolves the properties adapter for server.properties', function () {
    expect($this->registry->for(ck_snapshot('server.properties', "a=1\n")))->toBeInstanceOf(PropertiesAdapter::class);
});

it('resolves the yaml adapter for .yml and .yaml files', function () {
    expect($this->registry->for(ck_snapshot('config/paper-global.yml', "a: 1\n")))->toBeInstanceOf(YamlAdapter::class)
        ->and($this->registry->for(ck_snapshot('config/paper-global.yaml', "a: 1\n")))->toBeInstanceOf(YamlAdapter::class);
});

it('resolves the json adapter for .json files', function () {
    expect($this->registry->for(ck_snapshot('ops.json', '[]')))->toBeInstanceOf(JsonAdapter::class);
});

it('resolves the toml adapter for .toml files', function () {
    expect($this->registry->for(ck_snapshot('plugins/Example/config.toml', "a = 1\n")))->toBeInstanceOf(TomlAdapter::class);
});

it('throws a typed exception for an unrecognized extension', function () {
    expect(fn () => $this->registry->for(ck_snapshot('plugins/Example/readme.txt', 'hi')))
        ->toThrow(UnsupportedConfigFormat::class);
});
