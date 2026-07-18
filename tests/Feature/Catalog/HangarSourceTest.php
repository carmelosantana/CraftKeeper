<?php

use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Exceptions\PluginReleaseNotFound;
use App\Catalog\Sources\HangarSource;
use App\Models\CatalogCacheEntry;
use App\Plugins\PluginProvenance;
use Illuminate\Support\Facades\Http;

function hangarFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/fixtures/catalog/hangar/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->source = app(HangarSource::class);
});

/*
|--------------------------------------------------------------------------
| Normalization (search is summary-only: no downloadUrl/sha256 yet)
|--------------------------------------------------------------------------
*/

it('normalizes Hangar search results into summary PluginRelease entries with null download/sha256', function () {
    Http::fake(['hangar.papermc.io/api/v1/projects?*' => Http::response(hangarFixture('search-projects'), 200)]);

    $page = $this->source->search(new PluginSearchQuery(query: 'essentials'));

    expect($page->items)->toHaveCount(2);

    $essentials = collect($page->items)->firstWhere('slug', 'EssentialsX/EssentialsX');
    expect($essentials)->not->toBeNull()
        ->and($essentials->name)->toBe('EssentialsX')
        ->and($essentials->id->source)->toBe(PluginProvenance::Hangar)
        ->and($essentials->downloadUrl)->toBeNull()
        ->and($essentials->sha256)->toBeNull()
        ->and($essentials->minecraftVersions)->toEqualCanonicalizing(['1.20.1', '1.20.4'])
        ->and($essentials->platforms)->toBe(['paper'])
        ->and($essentials->sourceRepository)->toBe('https://github.com/EssentialsX/Essentials')
        ->and($essentials->license)->toBe('GPL-3.0');
});

/*
|--------------------------------------------------------------------------
| Timeout / retry: exactly two retries, ONLY for idempotent transient
| errors (connection failure or 5xx) — never for 4xx.
|--------------------------------------------------------------------------
*/

it('retries a 5xx exactly twice before succeeding on the third attempt', function () {
    Http::fake([
        'hangar.papermc.io/api/v1/projects?*' => Http::sequence()
            ->push(['message' => 'error'], 500)
            ->push(['message' => 'error'], 502)
            ->push(hangarFixture('search-projects'), 200),
    ]);

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->items)->toHaveCount(2)
        ->and($page->sourceResults[0]->degraded)->toBeFalse();

    Http::assertSentCount(3);
});

it('exhausts the retry budget (2 retries = 3 total attempts) on persistent 5xx and returns an empty, labeled-degraded page', function () {
    Http::fake([
        'hangar.papermc.io/api/v1/projects?*' => Http::response(['message' => 'error'], 500),
    ]);

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->items)->toBe([])
        ->and($page->sourceResults[0]->degraded)->toBeTrue()
        ->and($page->sourceResults[0]->message)->toContain('500');

    Http::assertSentCount(3);
});

it('never retries a 4xx — exactly one attempt, labeled degraded', function () {
    Http::fake([
        'hangar.papermc.io/api/v1/projects?*' => Http::response(['message' => 'bad request'], 400),
    ]);

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->items)->toBe([])
        ->and($page->sourceResults[0]->degraded)->toBeTrue();

    Http::assertSentCount(1);
});

it('treats a connection failure the same as a 5xx: retried, then labeled degraded once exhausted', function () {
    Http::fake(['hangar.papermc.io/api/v1/projects?*' => Http::failedConnection()]);

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->sourceResults[0]->degraded)->toBeTrue();
    Http::assertSentCount(3);
});

/*
|--------------------------------------------------------------------------
| Response-size limit — two independent checks, neither ever decodes
| the oversized body.
|--------------------------------------------------------------------------
*/

it('refuses a response whose declared Content-Length exceeds the limit, without reading the body', function () {
    config(['catalog.http.max_response_bytes' => 100]);

    Http::fake([
        'hangar.papermc.io/api/v1/projects?*' => Http::response(
            json_encode(['result' => []]),
            200,
            ['Content-Length' => '999999'],
        ),
    ]);

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->sourceResults[0]->degraded)->toBeTrue()
        ->and($page->sourceResults[0]->message)->toContain('999999');
});

it('refuses a response whose ACTUAL body exceeds the limit even when Content-Length is absent', function () {
    config(['catalog.http.max_response_bytes' => 50]);

    $oversizedBody = json_encode(['result' => array_fill(0, 20, ['name' => 'padding-to-exceed-fifty-bytes-of-json'])]);
    expect(strlen($oversizedBody))->toBeGreaterThan(50);

    Http::fake([
        'hangar.papermc.io/api/v1/projects?*' => Http::response($oversizedBody, 200),
    ]);

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->sourceResults[0]->degraded)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Caching: 15-minute freshness — a fresh cache hit makes NO HTTP call.
|--------------------------------------------------------------------------
*/

it('serves a second search within 15 minutes from cache, with no additional HTTP call', function () {
    Http::fake(['hangar.papermc.io/api/v1/projects?*' => Http::response(hangarFixture('search-projects'), 200)]);

    $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(1);

    $second = $this->source->search(new PluginSearchQuery);

    Http::assertSentCount(1);
    expect($second->sourceResults[0]->servedFromCache)->toBeTrue()
        ->and($second->sourceResults[0]->degraded)->toBeFalse()
        ->and($second->items)->toHaveCount(2);
});

it('attempts a live fetch again once the 15-minute freshness window has passed', function () {
    Http::fake(['hangar.papermc.io/api/v1/projects?*' => Http::response(hangarFixture('search-projects'), 200)]);

    $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(1);

    $this->travel(16)->minutes();

    $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(2);
});

/*
|--------------------------------------------------------------------------
| ETag/Last-Modified conditional revalidation — same mechanism as
| CraftKeeperCatalogSourceTest, exercised against a query-scoped Hangar
| cache row instead of the query-independent catalog document.
|--------------------------------------------------------------------------
*/

it('sends the stored ETag/Last-Modified as conditional headers on revalidation, serves the cached page (not re-normalized) on a 304, and extends freshness', function () {
    $etag = 'W/"hangar-etag-v1"';
    $lastModified = 'Wed, 21 Oct 2015 07:28:00 GMT';

    Http::fake([
        'hangar.papermc.io/api/v1/projects?*' => function ($request) use ($etag, $lastModified) {
            if ($request->hasHeader('If-None-Match')) {
                return Http::response('', 304, ['ETag' => $etag, 'Last-Modified' => $lastModified]);
            }

            return Http::response(hangarFixture('search-projects'), 200, ['ETag' => $etag, 'Last-Modified' => $lastModified]);
        },
    ]);

    $firstPage = $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(1);
    expect($firstPage->items)->toHaveCount(2);

    $entry = CatalogCacheEntry::query()->where('source', 'Hangar')->sole();
    expect($entry->etag)->toBe($etag)
        ->and($entry->last_modified)->toBe($lastModified);
    $freshUntilAfterFirstFetch = $entry->fresh_until;

    $this->travel(16)->minutes();

    $secondPage = $this->source->search(new PluginSearchQuery);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', $etag)
        && $request->hasHeader('If-Modified-Since', $lastModified));

    expect($secondPage->items)->toHaveCount(2)
        ->and(array_map(fn (PluginRelease $r) => $r->id->projectId, $secondPage->items))
        ->toBe(array_map(fn (PluginRelease $r) => $r->id->projectId, $firstPage->items))
        ->and($secondPage->sourceResults[0]->degraded)->toBeFalse()
        ->and($secondPage->sourceResults[0]->servedFromCache)->toBeFalse(); // a live 304 is still a "live" attempt, not a skipped-fetch cache hit

    $entry->refresh();
    expect($entry->fresh_until->gt($freshUntilAfterFirstFetch))->toBeTrue();

    // A third search inside the NEW freshness window never hits HTTP.
    $this->travel(10)->minutes();
    $thirdPage = $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(2);
    expect($thirdPage->sourceResults[0]->servedFromCache)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| release() — the authoritative, concrete-version lookup
|--------------------------------------------------------------------------
*/

it('release() resolves a concrete version with a real downloadUrl and sha256', function () {
    Http::fake([
        'hangar.papermc.io/api/v1/projects/EssentialsX/EssentialsX/versions/2.20.1' => Http::response(hangarFixture('version-essentialsx-2.20.1'), 200),
        'hangar.papermc.io/api/v1/projects/EssentialsX/EssentialsX' => Http::response(hangarFixture('project-essentialsx'), 200),
    ]);

    $release = $this->source->release(new PluginReleaseId(PluginProvenance::Hangar, 'EssentialsX/EssentialsX', '2.20.1'));

    expect($release)->toBeInstanceOf(PluginRelease::class)
        ->and($release->version)->toBe('2.20.1')
        ->and($release->downloadUrl)->toBe('https://hangar.papermc.io/api/v1/projects/EssentialsX/EssentialsX/versions/2.20.1/PAPER/download')
        ->and($release->sha256)->toBe('3771462a3440e7e100ac5b9ff25e05f886c730e4b1e12a30c267f723f59cb5ba')
        ->and($release->minecraftVersions)->toEqualCanonicalizing(['1.20.1', '1.20.4'])
        ->and($release->dependencies)->toHaveCount(1);
});

it('release() throws PluginReleaseNotFound on a 404 for the version lookup', function () {
    Http::fake([
        'hangar.papermc.io/api/v1/projects/EssentialsX/EssentialsX/versions/9.9.9' => Http::response(['message' => 'not found'], 404),
        'hangar.papermc.io/api/v1/projects/EssentialsX/EssentialsX' => Http::response(hangarFixture('project-essentialsx'), 200),
    ]);

    expect(fn () => $this->source->release(new PluginReleaseId(PluginProvenance::Hangar, 'EssentialsX/EssentialsX', '9.9.9')))
        ->toThrow(PluginReleaseNotFound::class);
});

it('release() with no version resolves the latest via the versions endpoint', function () {
    Http::fake([
        'hangar.papermc.io/api/v1/projects/EssentialsX/EssentialsX/versions?*' => Http::response(['result' => [hangarFixture('version-essentialsx-2.20.1')]], 200),
        'hangar.papermc.io/api/v1/projects/EssentialsX/EssentialsX' => Http::response(hangarFixture('project-essentialsx'), 200),
    ]);

    $release = $this->source->release(new PluginReleaseId(PluginProvenance::Hangar, 'EssentialsX/EssentialsX'));

    expect($release->version)->toBe('2.20.1');
});
