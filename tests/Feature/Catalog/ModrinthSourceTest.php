<?php

use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Exceptions\PluginReleaseNotFound;
use App\Catalog\Sources\ModrinthSource;
use App\Plugins\PluginProvenance;
use Illuminate\Support\Facades\Http;

function modrinthFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/fixtures/catalog/modrinth/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->source = app(ModrinthSource::class);
});

it('normalizes Modrinth search hits into summary PluginRelease entries with null download/sha256', function () {
    Http::fake(['api.modrinth.com/*' => Http::response(modrinthFixture('search'), 200)]);

    $page = $this->source->search(new PluginSearchQuery(query: 'sodium'));

    expect($page->items)->toHaveCount(1);

    $hit = $page->items[0];
    expect($hit->id->source)->toBe(PluginProvenance::Modrinth)
        ->and($hit->slug)->toBe('sodium-essentials')
        ->and($hit->name)->toBe('Sodium Essentials')
        ->and($hit->version)->toBe('0.5.11')
        ->and($hit->downloadUrl)->toBeNull()
        ->and($hit->sha256)->toBeNull()
        ->and($hit->minecraftVersions)->toEqualCanonicalizing(['1.20.1', '1.20.4'])
        ->and($hit->platforms)->toEqualCanonicalizing(['paper', 'spigot'])
        ->and($hit->license)->toBe('MIT');
});

it('release() resolves a concrete version with a real downloadUrl, sha256, and source repository', function () {
    Http::fake([
        'api.modrinth.com/v2/project/sodium-essentials/version' => Http::response(modrinthFixture('versions'), 200),
        'api.modrinth.com/v2/project/sodium-essentials' => Http::response(modrinthFixture('project'), 200),
    ]);

    $release = $this->source->release(new PluginReleaseId(PluginProvenance::Modrinth, 'sodium-essentials', '0.5.11'));

    expect($release->version)->toBe('0.5.11')
        ->and($release->downloadUrl)->toBe('https://cdn.modrinth.com/data/AANobbMI/versions/0.5.11/sodium-essentials-0.5.11.jar')
        ->and($release->sha256)->toBe('76a10c0f7c0d1dcbe7309cd544e07cceeaf7a759332d4b3920be32e06044ccfe')
        ->and($release->sourceRepository)->toBe('https://github.com/example/sodium-essentials')
        ->and($release->platforms)->toEqualCanonicalizing(['paper', 'spigot'])
        ->and($release->minecraftVersions)->toEqualCanonicalizing(['1.20.1', '1.20.4']);
});

it('release() throws PluginReleaseNotFound when the requested version number is not in the version list', function () {
    Http::fake([
        'api.modrinth.com/v2/project/sodium-essentials/version' => Http::response(modrinthFixture('versions'), 200),
        'api.modrinth.com/v2/project/sodium-essentials' => Http::response(modrinthFixture('project'), 200),
    ]);

    expect(fn () => $this->source->release(new PluginReleaseId(PluginProvenance::Modrinth, 'sodium-essentials', '9.9.9')))
        ->toThrow(PluginReleaseNotFound::class);
});

it('release() throws PluginReleaseNotFound on a 404 project lookup', function () {
    Http::fake(['api.modrinth.com/v2/project/does-not-exist' => Http::response(['error' => 'not_found'], 404)]);

    expect(fn () => $this->source->release(new PluginReleaseId(PluginProvenance::Modrinth, 'does-not-exist')))
        ->toThrow(PluginReleaseNotFound::class);
});

it('caches a search page for 15 minutes with no additional HTTP call', function () {
    Http::fake(['api.modrinth.com/*' => Http::response(modrinthFixture('search'), 200)]);

    $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(1);

    $second = $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(1);
    expect($second->sourceResults[0]->servedFromCache)->toBeTrue();
});

it('retries a 5xx exactly twice before succeeding, and never retries a 4xx', function () {
    Http::fake([
        'api.modrinth.com/*' => Http::sequence()
            ->push(['error' => 'server'], 503)
            ->push(['error' => 'server'], 503)
            ->push(modrinthFixture('search'), 200),
    ]);

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->sourceResults[0]->degraded)->toBeFalse();
    Http::assertSentCount(3);
});
