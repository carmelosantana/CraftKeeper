<?php

use App\Filesystem\MinecraftPath;
use App\Plugins\JarInspector;
use App\Plugins\PluginInspectionIssue;
use Illuminate\Support\Facades\File;
use Tests\fixtures\plugins\JarFixtureBuilder;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    File::makeDirectory($this->minecraftRoot.'/plugins', 0755, true, true);
    config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
});

/*
|--------------------------------------------------------------------------
| The brief's verbatim inspection test — do not alter the assertion shape.
|--------------------------------------------------------------------------
*/

it('reads plugin metadata without extracting archive entries', function () {
    JarFixtureBuilder::make()
        ->withPaperPluginYaml(<<<'YAML'
        name: Example
        version: '1.0.0'
        main: com.example.Example
        api-version: '1.21'
        YAML)
        ->writeTo($this->minecraftRoot.'/plugins/example.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/example.jar'));
    expect($plugin->name)->toBe('Example')
        ->and($plugin->sha256)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plugin->metadataSource)->toBe('paper-plugin.yml');
});

/*
|--------------------------------------------------------------------------
| Checksum, size, modified time
|--------------------------------------------------------------------------
*/

it('computes the sha256 of the raw jar file on disk, not of any decompressed entry', function () {
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: Checksum\nversion: '1.0.0'\n")
        ->writeTo($this->minecraftRoot.'/plugins/checksum.jar');

    $absolute = $this->minecraftRoot.'/plugins/checksum.jar';
    $expected = hash_file('sha256', $absolute);

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/checksum.jar'));

    expect($plugin->sha256)->toBe($expected)
        ->and($plugin->sizeBytes)->toBe(filesize($absolute))
        ->and($plugin->modifiedAt)->toBe(filemtime($absolute));
});

/*
|--------------------------------------------------------------------------
| plugin.yml fallback + hard/soft dependencies + api-version
|--------------------------------------------------------------------------
*/

it('falls back to plugin.yml when paper-plugin.yml is absent, and parses bukkit-style depend/softdepend', function () {
    JarFixtureBuilder::make()
        ->withPluginYaml(<<<'YAML'
        name: LegacyPlugin
        version: 2.3.4
        main: com.example.Legacy
        api-version: '1.20'
        depend: [Vault, WorldEdit]
        softdepend: [PlaceholderAPI]
        YAML)
        ->writeTo($this->minecraftRoot.'/plugins/legacy.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/legacy.jar'));

    expect($plugin->metadataSource)->toBe('plugin.yml')
        ->and($plugin->name)->toBe('LegacyPlugin')
        ->and($plugin->version)->toBe('2.3.4')
        ->and($plugin->mainClass)->toBe('com.example.Legacy')
        ->and($plugin->apiVersion)->toBe('1.20')
        ->and($plugin->hardDependencies)->toBe(['Vault', 'WorldEdit'])
        ->and($plugin->softDependencies)->toBe(['PlaceholderAPI'])
        ->and($plugin->diagnostics)->toBe([]);
});

it('prefers paper-plugin.yml over plugin.yml when both are present', function () {
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: PaperWins\nversion: '1.0.0'\n")
        ->withPluginYaml("name: BukkitLoses\nversion: '1.0.0'\n")
        ->writeTo($this->minecraftRoot.'/plugins/both.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/both.jar'));

    expect($plugin->metadataSource)->toBe('paper-plugin.yml')
        ->and($plugin->name)->toBe('PaperWins');
});

it('parses paper-plugin.yml style nested server dependencies, splitting on required', function () {
    JarFixtureBuilder::make()
        ->withPaperPluginYaml(<<<'YAML'
        name: PaperStyle
        version: '1.0.0'
        main: com.example.PaperStyle
        api-version: '1.21'
        dependencies:
          server:
            Vault:
              required: true
            LuckPerms:
              required: false
            ImplicitlyRequired: {}
        YAML)
        ->writeTo($this->minecraftRoot.'/plugins/paper-style.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/paper-style.jar'));

    expect($plugin->hardDependencies)->toEqualCanonicalizing(['Vault', 'ImplicitlyRequired'])
        ->and($plugin->softDependencies)->toBe(['LuckPerms']);
});

/*
|--------------------------------------------------------------------------
| Missing metadata → typed diagnostic, not a crash
|--------------------------------------------------------------------------
*/

it('returns a typed no-metadata diagnostic instead of crashing when neither metadata file is present', function () {
    JarFixtureBuilder::make()
        ->withEntry('com/example/Main.class', 'not real bytecode')
        ->withEntry('README.txt', 'hello')
        ->writeTo($this->minecraftRoot.'/plugins/no-metadata.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/no-metadata.jar'));

    expect($plugin->name)->toBeNull()
        ->and($plugin->metadataSource)->toBeNull()
        ->and($plugin->sha256)->toMatch('/^[a-f0-9]{64}$/')
        ->and($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::NoMetadata);
});

it('flags a foreign-platform archive (Velocity) instead of silently reporting no metadata', function () {
    JarFixtureBuilder::make()
        ->withEntry('velocity-plugin.json', '{"id":"example","version":"1.0.0"}')
        ->writeTo($this->minecraftRoot.'/plugins/velocity.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/velocity.jar'));

    expect($plugin->metadataSource)->toBeNull()
        ->and($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::ForeignPlatform)
        ->and($plugin->diagnostics[0]->message)->toContain('Velocity');
});

/*
|--------------------------------------------------------------------------
| Malformed / non-UTF-8 metadata → typed diagnostic, not a crash
|--------------------------------------------------------------------------
*/

it('returns a typed malformed-yaml diagnostic instead of letting a parser exception escape', function () {
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: Broken\n  version: not: valid: yaml: [")
        ->writeTo($this->minecraftRoot.'/plugins/broken.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/broken.jar'));

    expect($plugin->name)->toBeNull()
        ->and($plugin->metadataSource)->toBe('paper-plugin.yml')
        ->and($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::MalformedYaml);
});

it('returns a typed malformed-yaml diagnostic for invalid UTF-8 bytes instead of crashing', function () {
    JarFixtureBuilder::make()
        ->withEntry('paper-plugin.yml', "name: Bad\xFF\xFEEncoding\n")
        ->writeTo($this->minecraftRoot.'/plugins/bad-encoding.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/bad-encoding.jar'));

    expect($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::MalformedYaml);
});

it('returns a typed diagnostic when the document root is not a mapping', function () {
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("- just\n- a\n- list\n")
        ->writeTo($this->minecraftRoot.'/plugins/not-a-map.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/not-a-map.jar'));

    expect($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::InvalidMetadataStructure);
});

it('returns a typed diagnostic when the metadata has no usable name field', function () {
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("version: '1.0.0'\nmain: com.example.NoName\n")
        ->writeTo($this->minecraftRoot.'/plugins/no-name.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/no-name.jar'));

    expect($plugin->name)->toBeNull()
        ->and($plugin->metadataSource)->toBe('paper-plugin.yml')
        ->and($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::InvalidMetadataStructure);
});

/*
|--------------------------------------------------------------------------
| Hostile archives: never extract, never decompress a lied-about entry
|--------------------------------------------------------------------------
*/

it('refuses a metadata entry whose declared uncompressed size exceeds the cap, without decompressing it', function () {
    $huge = str_repeat('a', 400 * 1024);

    JarFixtureBuilder::make()
        ->withPaperPluginYaml($huge)
        ->writeTo($this->minecraftRoot.'/plugins/huge.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/huge.jar'));

    expect($plugin->name)->toBeNull()
        ->and($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::MetadataTooLarge)
        ->and($plugin->diagnostics[0]->message)->toContain('declares an uncompressed size');
});

it('aborts a metadata read whose declared size lies but whose real decompressed bytes exceed the cap', function () {
    $realContent = str_repeat('a', 400 * 1024); // well over the 256 KiB cap
    $absolute = $this->minecraftRoot.'/plugins/lying-size.jar';

    JarFixtureBuilder::make()->writeLyingSizeEntryTo($absolute, 'paper-plugin.yml', $realContent, lieSize: 50);

    // Prove the lie actually took, so this test is exercising the
    // "declared size" defense being bypassed, not merely repeating the
    // previous huge-declared-size test.
    $zip = new ZipArchive;
    $zip->open($absolute);
    expect($zip->statIndex(0)['size'])->toBe(50);
    $zip->close();

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/lying-size.jar'));

    expect($plugin->name)->toBeNull()
        ->and($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::MetadataTooLarge)
        ->and($plugin->diagnostics[0]->message)->toContain('before it finished decompressing');
});

it('refuses an archive with more than 10,000 entries without inspecting any of them', function () {
    JarFixtureBuilder::make()
        ->withManyEntries(10_001)
        ->writeTo($this->minecraftRoot.'/plugins/zip-bomb-entries.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/zip-bomb-entries.jar'));

    expect($plugin->name)->toBeNull()
        ->and($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::TooManyEntries);
});

it('accepts an archive with exactly 10,000 entries plus real metadata', function () {
    JarFixtureBuilder::make()
        ->withPaperPluginYaml("name: AtTheCap\nversion: '1.0.0'\n")
        ->withManyEntries(9_999)
        ->writeTo($this->minecraftRoot.'/plugins/at-cap.jar');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/at-cap.jar'));

    expect($plugin->name)->toBe('AtTheCap')
        ->and($plugin->diagnostics)->toBe([]);
});

it('ignores traversal- and absolute-path-named entries and never writes anything outside the archive', function () {
    JarFixtureBuilder::make()
        ->withEntry('../../../etc/passwd', "root:x:0:0:root:/root:/bin/bash\n")
        ->withEntry('/etc/shadow', "root:!:0:0:0:::\n")
        ->withEntry('..\\..\\windows\\system32\\config\\sam', 'not really')
        ->withPaperPluginYaml("name: Traversal\nversion: '1.0.0'\n")
        ->writeTo($this->minecraftRoot.'/plugins/traversal.jar');

    $before = collect(File::allFiles($this->minecraftRoot))->map->getPathname()->sort()->values();

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/traversal.jar'));

    $after = collect(File::allFiles($this->minecraftRoot))->map->getPathname()->sort()->values();

    expect($plugin->name)->toBe('Traversal')
        ->and($plugin->diagnostics)->toBe([])
        ->and($after)->toEqual($before); // inspecting the archive never created or altered any file
});

it('returns a typed diagnostic for a corrupt, unreadable archive instead of crashing', function () {
    file_put_contents($this->minecraftRoot.'/plugins/corrupt.jar', 'this is not a zip file at all');

    $plugin = app(JarInspector::class)->inspect(MinecraftPath::fromUserInput('plugins/corrupt.jar'));

    expect($plugin->name)->toBeNull()
        ->and($plugin->diagnostics)->toHaveCount(1)
        ->and($plugin->diagnostics[0]->issue)->toBe(PluginInspectionIssue::UnreadableArchive)
        ->and($plugin->sha256)->toMatch('/^[a-f0-9]{64}$/'); // still computed from the raw bytes on disk
});
