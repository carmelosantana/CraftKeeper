<?php

use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Exceptions\PluginReleaseNotFound;
use App\Catalog\Sources\CraftKeeperCatalogSource;
use App\Models\CatalogCacheEntry;
use App\Plugins\PluginProvenance;
use Illuminate\Support\Facades\Http;

function craftKeeperFixtureRaw(string $name): string
{
    return file_get_contents(base_path("tests/fixtures/catalog/craftkeeper/{$name}.json"));
}

beforeEach(function () {
    $this->source = app(CraftKeeperCatalogSource::class);
});

/*
|--------------------------------------------------------------------------
| Each release category (brief step 1) — a real document with all four
| mixed together must not crash, and must handle each as its category.
|--------------------------------------------------------------------------
*/

it('search() returns only the latest VALID, non-withdrawn release — invalid-hash and missing-version releases are silently skipped, not crashes', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-mixed'), 200)]);

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->sourceResults[0]->degraded)->toBeFalse()
        ->and($page->items)->toHaveCount(1);

    $item = $page->items[0];
    expect($item->version)->toBe('2.20.1')
        ->and($item->withdrawn)->toBeFalse()
        ->and($item->sha256)->toBe('3771462a3440e7e100ac5b9ff25e05f886c730e4b1e12a30c267f723f59cb5ba');
});

it('release() resolves a specific WITHDRAWN version directly, marked withdrawn — never a 404', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-mixed'), 200)]);

    $release = $this->source->release(new PluginReleaseId(PluginProvenance::Catalog, 'essentialsx', '2.19.0'));

    expect($release->withdrawn)->toBeTrue()
        ->and($release->version)->toBe('2.19.0');
});

it('release() with no version never resolves to a withdrawn release — "latest" means latest ACTIVE', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-mixed'), 200)]);

    $release = $this->source->release(new PluginReleaseId(PluginProvenance::Catalog, 'essentialsx'));

    expect($release->version)->toBe('2.20.1')
        ->and($release->withdrawn)->toBeFalse();
});

it('release() throws PluginReleaseNotFound for the invalid-hash release version — it was never normalized at all', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-mixed'), 200)]);

    expect(fn () => $this->source->release(new PluginReleaseId(PluginProvenance::Catalog, 'essentialsx', '2.18.0')))
        ->toThrow(PluginReleaseNotFound::class);
});

it('a document containing every category together does not crash — the whole fetch still succeeds', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-mixed'), 200)]);

    expect(fn () => $this->source->search(new PluginSearchQuery))->not->toThrow(Throwable::class);
});

/*
|--------------------------------------------------------------------------
| 7-day stale-while-error retention (the brief's named CraftKeeper
| Catalog requirement) — distinct from the 15-minute page freshness.
|--------------------------------------------------------------------------
*/

it('serves the cached catalog fresh for 15 minutes, then attempts a live fetch again', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-basic'), 200)]);

    $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(1);

    $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(1); // still fresh, no second call

    $this->travel(16)->minutes();

    $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(2);
});

it('stays available (stale-while-error) up to 7 days after the last success once live fetches start failing', function () {
    // A single Http::fake() call for the whole test: see
    // UnifiedCatalogServiceTest's end-to-end test docblock for why
    // calling Http::fake() a second time mid-test does NOT replace
    // earlier stubs. One success (for the first search() below),
    // followed by enough 500s to cover the second search()'s full
    // retry budget (1 initial + 2 retries).
    Http::fake([
        'raw.githubusercontent.com/*' => Http::sequence()
            ->push(craftKeeperFixtureRaw('catalog-basic'), 200)
            ->push(['message' => 'error'], 500)
            ->push(['message' => 'error'], 500)
            ->push(['message' => 'error'], 500),
    ]);

    $this->source->search(new PluginSearchQuery);

    $this->travel(6)->days();

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->sourceResults[0]->degraded)->toBeTrue()
        ->and($page->sourceResults[0]->servedFromCache)->toBeTrue()
        ->and($page->sourceResults[0]->stale)->toBeTrue()
        ->and($page->items)->not->toBeEmpty();
});

it('stops serving the stale catalog once past the 7-day retention window — degraded and empty, not crashed', function () {
    Http::fake([
        'raw.githubusercontent.com/*' => Http::sequence()
            ->push(craftKeeperFixtureRaw('catalog-basic'), 200)
            ->push(['message' => 'error'], 500)
            ->push(['message' => 'error'], 500)
            ->push(['message' => 'error'], 500),
    ]);

    $this->source->search(new PluginSearchQuery);

    $this->travel(8)->days();

    $page = $this->source->search(new PluginSearchQuery);

    expect($page->sourceResults[0]->degraded)->toBeTrue()
        ->and($page->sourceResults[0]->servedFromCache)->toBeFalse()
        ->and($page->items)->toBe([]);
});

it('persists the catalog snapshot as a single query-independent cache row', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-basic'), 200)]);

    $this->source->search(new PluginSearchQuery(query: 'essentials'));
    $this->source->search(new PluginSearchQuery(query: 'vault'));

    expect(CatalogCacheEntry::query()->where('source', 'Catalog')->count())->toBe(1);
    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Text/version/platform filtering (client-side, since the cache key is
| query-independent for this source).
|--------------------------------------------------------------------------
*/

it('filters search results by free-text query against name/slug/description', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-basic'), 200)]);

    $page = $this->source->search(new PluginSearchQuery(query: 'vault'));

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->slug)->toBe('vault');
});

it('filters search results by requested Minecraft version', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(craftKeeperFixtureRaw('catalog-basic'), 200)]);

    $page = $this->source->search(new PluginSearchQuery(minecraftVersion: '1.20.4'));

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->slug)->toBe('essentialsx');
});

/*
|--------------------------------------------------------------------------
| ETag/Last-Modified conditional revalidation (App\Catalog\Transport\
| CatalogHttpClient) — a validator returned on the first 200 is persisted
| alongside the cache row, sent back as If-None-Match/If-Modified-Since
| once the 15-minute freshness window elapses, and a 304 short-circuits
| to the CACHED payload (no re-normalization) while
| CatalogCache::touchFreshness() extends the freshness window so the
| NEXT read doesn't re-hit HTTP either.
|--------------------------------------------------------------------------
*/

it('stores the ETag/Last-Modified from the first 200, sends them back as conditional headers on revalidation, serves the cached payload (not re-normalized) on a 304, and extends freshness', function () {
    $etag = '"catalog-etag-v1"';
    $lastModified = 'Wed, 21 Oct 2015 07:28:00 GMT';

    // A single Http::fake() stub for the whole test (see
    // UnifiedCatalogServiceTest's docblock for why a second Http::fake()
    // call would not replace this one): it inspects the OUTGOING request
    // itself — a real conditional revalidation only ever happens once a
    // cached validator exists, so seeing If-None-Match on the wire is
    // itself proof the code reached that branch, not merely an assumption.
    Http::fake([
        'raw.githubusercontent.com/*' => function ($request) use ($etag, $lastModified) {
            if ($request->hasHeader('If-None-Match')) {
                return Http::response('', 304, ['ETag' => $etag, 'Last-Modified' => $lastModified]);
            }

            return Http::response(craftKeeperFixtureRaw('catalog-basic'), 200, ['ETag' => $etag, 'Last-Modified' => $lastModified]);
        },
    ]);

    $firstPage = $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(1);
    expect($firstPage->items)->not->toBeEmpty();

    $entry = CatalogCacheEntry::query()->where('source', 'Catalog')->sole();
    expect($entry->etag)->toBe($etag)
        ->and($entry->last_modified)->toBe($lastModified);
    $freshUntilAfterFirstFetch = $entry->fresh_until;

    // Past the 15-minute freshness window — the next search() attempts a
    // live fetch again, which is the revalidation trigger.
    $this->travel(16)->minutes();

    $secondPage = $this->source->search(new PluginSearchQuery);

    Http::assertSentCount(2);
    // The stored validator values were SENT, not just any headers — a
    // regression that drops them (or sends stale/blank ones) fails here.
    Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', $etag)
        && $request->hasHeader('If-Modified-Since', $lastModified));

    // Served from the CACHED payload — identical items, not a
    // re-normalized document — and never labeled degraded.
    expect(array_map(fn ($r) => $r->version, $secondPage->items))
        ->toBe(array_map(fn ($r) => $r->version, $firstPage->items))
        ->and($secondPage->sourceResults[0]->degraded)->toBeFalse();

    // touchFreshness() extended the window past what it was right after
    // the first fetch (a regression that never reaches notModified()
    // would leave this untouched, or would instead go through put()).
    $entry->refresh();
    expect($entry->fresh_until->gt($freshUntilAfterFirstFetch))->toBeTrue();

    // A third search inside the NEW freshness window is a pure cache
    // hit — no third HTTP call at all.
    $this->travel(10)->minutes();
    $thirdPage = $this->source->search(new PluginSearchQuery);
    Http::assertSentCount(2);
    expect($thirdPage->sourceResults[0]->servedFromCache)->toBeTrue();
});
