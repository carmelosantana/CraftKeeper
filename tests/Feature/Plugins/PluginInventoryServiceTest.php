<?php

use App\Models\PluginArtifact;
use App\Models\PluginInstallation;
use App\Plugins\PluginInventoryService;
use Illuminate\Support\Facades\File;
use Tests\fixtures\plugins\JarFixtureBuilder;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    File::makeDirectory($this->minecraftRoot.'/plugins', 0755, true, true);
    config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
    $this->service = app(PluginInventoryService::class);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
});

function putPluginJar(string $root, string $filename, string $name, string $version = '1.0.0', string $extraYaml = ''): void
{
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: {$name}\nversion: '{$version}'\n{$extraYaml}")
        ->writeTo($root.'/plugins/'.$filename);
}

/*
|--------------------------------------------------------------------------
| Additions
|--------------------------------------------------------------------------
*/

it('records a newly discovered plugin as an addition with Manual provenance', function () {
    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials');

    $result = $this->service->reconcile();

    expect($result->additions)->toHaveCount(1)
        ->and($result->removals)->toBe([])
        ->and($result->changes)->toBe([])
        ->and($result->additions[0]->relative_path)->toBe('plugins/Essentials.jar')
        ->and($result->additions[0]->name)->toBe('Essentials')
        ->and($result->additions[0]->provenance)->toBe('Manual')
        ->and($result->additions[0]->enabled)->toBeTrue()
        ->and(PluginInstallation::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Determinism
|--------------------------------------------------------------------------
*/

it('is deterministic: reconciling twice with no disk changes produces no additions/removals/changes the second time', function () {
    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials');
    putPluginJar($this->minecraftRoot, 'Vault.jar', 'Vault');

    $this->service->reconcile();
    $second = $this->service->reconcile();

    expect($second->additions)->toBe([])
        ->and($second->removals)->toBe([])
        ->and($second->changes)->toBe([])
        ->and($second->unchanged)->toHaveCount(2)
        ->and(PluginInstallation::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Removals — never deleted, missing_since set instead
|--------------------------------------------------------------------------
*/

it('marks a tracked plugin missing_since (without deleting it) when its file disappears, and clears it on reappearance', function () {
    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials');
    $this->service->reconcile();

    unlink($this->minecraftRoot.'/plugins/Essentials.jar');
    $result = $this->service->reconcile();

    $installation = PluginInstallation::query()->sole();

    expect($result->removals)->toHaveCount(1)
        ->and($installation->missing_since)->not->toBeNull()
        ->and($installation->name)->toBe('Essentials'); // history preserved, not wiped

    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials');
    $this->service->reconcile();

    expect($installation->refresh()->missing_since)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Changes — checksum differs, provenance preserved
|--------------------------------------------------------------------------
*/

it('records a change when the on-disk checksum differs, preserving existing Manual provenance', function () {
    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials', '1.0.0');
    $this->service->reconcile();
    $originalSha256 = PluginInstallation::query()->sole()->sha256;

    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials', '1.0.1');
    $result = $this->service->reconcile();

    $installation = PluginInstallation::query()->sole();

    expect($result->changes)->toHaveCount(1)
        ->and($installation->version)->toBe('1.0.1')
        ->and($installation->sha256)->not->toBe($originalSha256)
        ->and($installation->provenance)->toBe('Manual');
});

/*
|--------------------------------------------------------------------------
| Provenance attribution — only on an EXACT checksum match
|--------------------------------------------------------------------------
*/

it('attributes provenance to a known source only when the checksum exactly matches a recorded artifact', function () {
    $absolute = $this->minecraftRoot.'/plugins/Known.jar';
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: Known\nversion: '1.0.0'\n")
        ->writeTo($absolute);
    $sha256 = hash_file('sha256', $absolute);

    PluginArtifact::query()->create([
        'sha256' => $sha256,
        'size_bytes' => filesize($absolute),
        'source' => 'Hangar',
        'version' => '1.0.0',
    ]);

    $result = $this->service->reconcile();

    expect($result->additions[0]->provenance)->toBe('Hangar');
});

it('does not overwrite an existing Manual provenance when a new checksum does not match any known artifact', function () {
    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials', '1.0.0');
    $this->service->reconcile();

    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials', '2.0.0');
    $this->service->reconcile();

    expect(PluginInstallation::query()->sole()->provenance)->toBe('Manual');
});

/*
|--------------------------------------------------------------------------
| Disabled plugins
|--------------------------------------------------------------------------
*/

it('recognizes a .jar.disabled file under its logical (enabled-form) path with enabled=false', function () {
    putPluginJar($this->minecraftRoot, 'Essentials.jar.disabled', 'Essentials');

    $result = $this->service->reconcile();

    expect($result->additions)->toHaveCount(1)
        ->and($result->additions[0]->relative_path)->toBe('plugins/Essentials.jar')
        ->and($result->additions[0]->enabled)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Duplicate names — detected, never silently merged
|--------------------------------------------------------------------------
*/

it('flags duplicate plugin names across the inventory without merging the two installations', function () {
    putPluginJar($this->minecraftRoot, 'Essentials-a.jar', 'Essentials');
    putPluginJar($this->minecraftRoot, 'Essentials-b.jar', 'Essentials');

    $result = $this->service->reconcile();

    expect($result->duplicateNames)->toHaveKey('Essentials')
        ->and($result->duplicateNames['Essentials'])->toEqualCanonicalizing(['plugins/Essentials-a.jar', 'plugins/Essentials-b.jar'])
        ->and(PluginInstallation::query()->count())->toBe(2)
        ->and(PluginInstallation::query()->where('duplicate_name', true)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Same logical path present in both forms at once — a conflict, not a guess
|--------------------------------------------------------------------------
*/

it('flags it as a conflict, and does not fabricate an installation, when both the enabled and disabled forms exist on disk at once', function () {
    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials');
    putPluginJar($this->minecraftRoot, 'Essentials.jar.disabled', 'Essentials');

    $result = $this->service->reconcile();

    expect($result->pathConflicts)->toHaveKey('plugins/Essentials.jar')
        ->and($result->pathConflicts['plugins/Essentials.jar'])->toEqualCanonicalizing(['Essentials.jar', 'Essentials.jar.disabled'])
        ->and($result->additions)->toBe([])
        ->and(PluginInstallation::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Compatibility evidence is attached during reconciliation
|--------------------------------------------------------------------------
*/

it('computes and stores a compatibility assessment for every reconciled plugin', function () {
    putPluginJar($this->minecraftRoot, 'NeedsVault.jar', 'NeedsVault', extraYaml: "depend: [Vault]\n");

    $result = $this->service->reconcile();

    expect($result->additions[0]->compatibility_state)->not->toBeNull()
        ->and($result->additions[0]->compatibility_evidence)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| A single unsafe-on-disk filename never breaks reconciliation for
| everything else in the same scan
|--------------------------------------------------------------------------
*/

it('skips a file with an unsafe name (e.g. a reserved device name) instead of crashing the whole reconciliation', function () {
    // "con.jar" is a perfectly legal filename on the Linux filesystem
    // this test suite runs on, but App\Filesystem\MinecraftPath always
    // refuses it (a reserved Windows device name) — reconcile() must
    // skip it, not let that exception take down every other plugin in
    // the same scan.
    file_put_contents($this->minecraftRoot.'/plugins/con.jar', 'not a real jar');
    putPluginJar($this->minecraftRoot, 'Essentials.jar', 'Essentials');

    $result = $this->service->reconcile();

    expect($result->additions)->toHaveCount(1)
        ->and($result->additions[0]->name)->toBe('Essentials');
});

/*
|--------------------------------------------------------------------------
| An empty/missing plugins directory never crashes
|--------------------------------------------------------------------------
*/

it('returns an empty reconciliation, not a crash, when the plugins directory does not exist', function () {
    File::deleteDirectory($this->minecraftRoot.'/plugins');

    $result = $this->service->reconcile();

    expect($result->additions)->toBe([])
        ->and($result->removals)->toBe([])
        ->and($result->changes)->toBe([]);
});
