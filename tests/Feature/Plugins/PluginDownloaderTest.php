<?php

use App\Plugins\Exceptions\PluginArtifactTooLarge;
use App\Plugins\Exceptions\PluginChecksumMismatch;
use App\Plugins\PluginDownloader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\Plugins\PluginReleaseFactory;
use Tests\Support\TempMinecraftRoot;

/*
|--------------------------------------------------------------------------
| The brief's Step 1 test, verbatim intent — adapted to the REAL
| App\Catalog\Data\PluginRelease constructor/fromArray shape Task 14
| actually shipped (the brief's own snippet uses an illustrative
| fromArray({id: 'catalog:example:1.0.0', ...}) shape that predates —
| and does not match — PluginReleaseId::fromArray()'s real nested
| {source, projectId, version} shape; see decisions.md).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    $this->dataRoot = TempMinecraftRoot::createDataRoot();
    File::makeDirectory($this->minecraftRoot.'/plugins', 0755, true, true);
    config([
        'craftkeeper.minecraft_root' => $this->minecraftRoot,
        'craftkeeper.data_root' => $this->dataRoot,
    ]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
    TempMinecraftRoot::destroy($this->dataRoot);
});

it('rejects a downloaded artifact whose checksum differs from release metadata', function () {
    Http::fake(['*' => Http::response('not-the-published-jar')]);

    $release = PluginReleaseFactory::make();

    expect(fn () => app(PluginDownloader::class)->download($release))
        ->toThrow(PluginChecksumMismatch::class);
});

it('never lets a checksum-mismatched download reach /minecraft or linger in quarantine', function () {
    Http::fake(['*' => Http::response('not-the-published-jar')]);

    $release = PluginReleaseFactory::make();

    try {
        app(PluginDownloader::class)->download($release);
    } catch (PluginChecksumMismatch) {
        // expected
    }

    expect(glob($this->minecraftRoot.'/plugins/*') ?: [])->toBe([])
        ->and(glob($this->dataRoot.'/quarantine/*') ?: [])->toBe([]);
});

it('accepts a downloaded artifact whose checksum exactly matches the release metadata', function () {
    $bytes = 'this-is-the-real-published-jar-bytes';
    Http::fake(['*' => Http::response($bytes)]);

    $release = PluginReleaseFactory::make(sha256: hash('sha256', $bytes));

    $artifact = app(PluginDownloader::class)->download($release);

    expect($artifact->sha256)->toBe(hash('sha256', $bytes))
        ->and($artifact->sizeBytes)->toBe(strlen($bytes))
        ->and(file_get_contents($artifact->absolutePath))->toBe($bytes)
        ->and(glob($this->minecraftRoot.'/plugins/*') ?: [])->toBe([]);
});

it('refuses an artifact whose declared Content-Length exceeds the configured cap, without reading the body', function () {
    config(['craftkeeper.plugins.max_artifact_bytes' => 10]);

    Http::fake(['*' => Http::response('this response body is more than ten bytes long', 200, [
        'Content-Length' => '9999',
    ])]);

    $release = PluginReleaseFactory::make();

    expect(fn () => app(PluginDownloader::class)->download($release))
        ->toThrow(PluginArtifactTooLarge::class);

    expect(glob($this->minecraftRoot.'/plugins/*') ?: [])->toBe([])
        ->and(glob($this->dataRoot.'/quarantine/*') ?: [])->toBe([]);
});

it('refuses an oversized artifact based on actual bytes streamed, even with no honest declared size', function () {
    config(['craftkeeper.plugins.max_artifact_bytes' => 10]);

    // No Content-Length header at all — the ONLY thing that can catch
    // this is the running-total check on actually-read bytes.
    Http::fake(['*' => Http::response(str_repeat('x', 500))]);

    $release = PluginReleaseFactory::make();

    expect(fn () => app(PluginDownloader::class)->download($release))
        ->toThrow(PluginArtifactTooLarge::class);

    expect(glob($this->minecraftRoot.'/plugins/*') ?: [])->toBe([])
        ->and(glob($this->dataRoot.'/quarantine/*') ?: [])->toBe([]);
});
