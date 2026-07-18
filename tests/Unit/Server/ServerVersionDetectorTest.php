<?php

use App\Server\ServerVersionDetector;
use Illuminate\Support\Facades\File;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
});

function versionDetector(): ServerVersionDetector
{
    return new ServerVersionDetector;
}

it('reports unavailable, with a reason, when neither a JAR nor a log banner exists — never a fabricated version', function () {
    $version = versionDetector()->detect();

    expect($version->known)->toBeFalse()
        ->and($version->label)->toBeNull()
        ->and($version->reason)->not->toBeNull();
});

it('detects the version from a root-level Paper JAR filename', function () {
    file_put_contents($this->minecraftRoot.'/paper-1.21.4-130.jar', 'not a real jar');

    $version = versionDetector()->detect();

    expect($version->known)->toBeTrue()
        ->and($version->label)->toBe('Paper 1.21.4')
        ->and($version->source)->toBe('jar');
});

it('detects the version from a Purpur JAR filename', function () {
    file_put_contents($this->minecraftRoot.'/purpur-1.20.4.jar', 'not a real jar');

    $version = versionDetector()->detect();

    expect($version->known)->toBeTrue()
        ->and($version->label)->toBe('Purpur 1.20.4')
        ->and($version->source)->toBe('jar');
});

it('detects the version from the Paper startup log banner when no JAR is found', function () {
    File::makeDirectory($this->minecraftRoot.'/logs', 0755, true, true);
    file_put_contents(
        $this->minecraftRoot.'/logs/latest.log',
        "[00:00:01 INFO]: Starting minecraft server version 1.21.4\n".
        "[00:00:02 INFO]: This server is running Paper version 1.21.4-130-abc123 (MC: 1.21.4)\n",
    );

    $version = versionDetector()->detect();

    expect($version->known)->toBeTrue()
        ->and($version->label)->toBe('Paper 1.21.4-130-abc123')
        ->and($version->source)->toBe('log');
});

it('detects a vanilla version from the plain startup banner', function () {
    File::makeDirectory($this->minecraftRoot.'/logs', 0755, true, true);
    file_put_contents(
        $this->minecraftRoot.'/logs/latest.log',
        "[00:00:01 INFO]: Starting minecraft server version 1.21.4\n",
    );

    $version = versionDetector()->detect();

    expect($version->known)->toBeTrue()
        ->and($version->label)->toBe('Vanilla 1.21.4')
        ->and($version->source)->toBe('log');
});

it('prefers a JAR filename over a log banner when both exist', function () {
    file_put_contents($this->minecraftRoot.'/paper-1.21.4-130.jar', 'not a real jar');

    File::makeDirectory($this->minecraftRoot.'/logs', 0755, true, true);
    file_put_contents(
        $this->minecraftRoot.'/logs/latest.log',
        "[00:00:01 INFO]: This server is running Paper version 1.20.1-99 (MC: 1.20.1)\n",
    );

    $version = versionDetector()->detect();

    expect($version->source)->toBe('jar')
        ->and($version->label)->toBe('Paper 1.21.4');
});

it('reports unavailable when the Minecraft root itself is unavailable', function () {
    config(['craftkeeper.minecraft_root' => '/this/path/does/not/exist']);

    $version = versionDetector()->detect();

    expect($version->known)->toBeFalse()
        ->and($version->reason)->toContain('Minecraft root');
});
