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

/*
|--------------------------------------------------------------------------
| version_history.json — the third source, added in 1.1.2
|--------------------------------------------------------------------------
|
| Both earlier strategies fail on an ordinary Paper server of the kind
| CraftKeeper's primary supported deployment ships: the JAR is the
| bootstrap's generic `paperclip.jar` (no version to parse) and the startup
| banner is gone once logs/latest.log rotates. Detection therefore worked on
| a fresh boot and silently stopped a day later.
|
*/

it('detects the version from version_history.json when the JAR is the generic paperclip.jar and the log has rotated', function () {
    // Exactly the real-world shape: a versionless bootstrap JAR...
    file_put_contents($this->minecraftRoot.'/paperclip.jar', 'not a real jar');

    // ...and a latest.log that has rotated past its startup banner, so it
    // contains only ordinary runtime chatter.
    File::ensureDirectoryExists($this->minecraftRoot.'/logs');
    file_put_contents(
        $this->minecraftRoot.'/logs/latest.log',
        "[00:00:00] [RCON Listener #1/INFO]: Thread RCON Client /172.28.0.3 started\n"
    );

    file_put_contents(
        $this->minecraftRoot.'/version_history.json',
        json_encode(['currentVersion' => '1.21.4-130-abcdef1 (MC: 1.21.4)'])
    );

    $version = versionDetector()->detect();

    // Verbatim: the file does not say WHICH distribution wrote it (Paper,
    // Purpur and Folia all use Paperclip), so no brand is prefixed and the
    // string is not reformatted.
    expect($version->known)->toBeTrue()
        ->and($version->label)->toBe('1.21.4-130-abcdef1 (MC: 1.21.4)')
        ->and($version->source)->toBe('version_history');
});

it('prefers a parseable JAR filename over version_history.json, leaving existing installs unchanged', function () {
    file_put_contents($this->minecraftRoot.'/paper-1.21.4-130.jar', 'not a real jar');
    file_put_contents(
        $this->minecraftRoot.'/version_history.json',
        json_encode(['currentVersion' => '9.9.9-1-deadbee (MC: 9.9.9)'])
    );

    $version = versionDetector()->detect();

    expect($version->label)->toBe('Paper 1.21.4')
        ->and($version->source)->toBe('jar');
});

it('ignores a malformed or empty version_history.json rather than labelling anything', function (string $contents) {
    file_put_contents($this->minecraftRoot.'/version_history.json', $contents);

    $version = versionDetector()->detect();

    expect($version->known)->toBeFalse()
        ->and($version->label)->toBeNull();
})->with([
    'not json at all' => 'this is not json',
    'json but not an object' => '"a bare string"',
    'object without the key' => '{"otherKey":"1.2.3"}',
    'key present but empty' => '{"currentVersion":"   "}',
    'key present but not a string' => '{"currentVersion":{"nested":true}}',
]);

it('refuses an absurdly long currentVersion rather than passing junk to the UI', function () {
    file_put_contents(
        $this->minecraftRoot.'/version_history.json',
        json_encode(['currentVersion' => str_repeat('A', 500)])
    );

    expect(versionDetector()->detect()->known)->toBeFalse();
});
