<?php

use App\Plugins\Exceptions\PluginArtifactTooLarge;
use App\Plugins\PluginUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Support\TempMinecraftRoot;

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

it('quarantines an uploaded jar, computing its own sha256 as identity', function () {
    $content = 'a-fake-jar-payload';
    $file = UploadedFile::fake()->createWithContent('MyPlugin.jar', $content);

    $artifact = app(PluginUploadService::class)->store($file);

    expect($artifact->sha256)->toBe(hash('sha256', $content))
        ->and($artifact->sizeBytes)->toBe(strlen($content))
        ->and(file_get_contents($artifact->absolutePath))->toBe($content)
        ->and(glob($this->minecraftRoot.'/plugins/*') ?: [])->toBe([]);
});

it('refuses an oversized upload based on the file\'s declared size, without touching /minecraft', function () {
    config(['craftkeeper.plugins.max_artifact_bytes' => 10]);

    $file = UploadedFile::fake()->create('Huge.jar', 50); // 50 KB, declared via filesize()

    expect(fn () => app(PluginUploadService::class)->store($file))
        ->toThrow(PluginArtifactTooLarge::class);

    expect(glob($this->minecraftRoot.'/plugins/*') ?: [])->toBe([])
        ->and(glob($this->dataRoot.'/quarantine/*') ?: [])->toBe([]);
});
