<?php

use App\Catalog\Data\PluginDependencyRef;
use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchPage;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Data\PluginSourceResult;
use App\Catalog\UnifiedCatalogService;
use App\Models\PluginInstallation;
use App\Plugins\PluginCompatibilityEvidence;
use App\Plugins\PluginProvenance;
use Illuminate\Support\Facades\Http;
use Tests\Support\Catalog\FakePluginSource;

/**
 * @param  list<PluginDependencyRef>  $dependencies
 * @param  list<PluginCompatibilityEvidence>  $evidence
 */
function catalogRelease(
    PluginProvenance $source,
    string $projectId,
    string $name,
    ?string $version = '1.0.0',
    array $minecraftVersions = ['1.20.1'],
    array $dependencies = [],
    array $evidence = [],
    bool $withdrawn = false,
): PluginRelease {
    return new PluginRelease(
        id: new PluginReleaseId($source, $projectId, $version),
        slug: $projectId,
        name: $name,
        description: "{$name} description",
        projectUrl: "https://example.test/{$projectId}",
        sourceUrl: "https://example.test/{$projectId}#{$version}",
        license: 'MIT',
        sourceRepository: "https://github.com/example/{$projectId}",
        version: $version ?? '1.0.0',
        minecraftVersions: $minecraftVersions,
        platforms: ['paper'],
        dependencies: $dependencies,
        downloadUrl: "https://example.test/{$projectId}.jar",
        sha256: str_repeat('a', 64),
        releasedAt: new DateTimeImmutable('2026-01-01T00:00:00Z'),
        withdrawn: $withdrawn,
        signature: null,
        compatibilityEvidence: $evidence,
    );
}

/**
 * @param  list<PluginRelease>  $items
 */
function sourcePage(PluginProvenance $source, array $items, bool $degraded = false, ?string $message = null): PluginSearchPage
{
    $result = $degraded
        ? PluginSourceResult::degraded($source, $message ?? 'failed', servedFromCache: false, stale: false)
        : PluginSourceResult::ok($source, servedFromCache: false, stale: false);

    return new PluginSearchPage($items, [$result]);
}

/*
|--------------------------------------------------------------------------
| Merge without erasing provenance
|--------------------------------------------------------------------------
*/

it('merges results from every source, retaining each one\'s own source badge and URL', function () {
    $catalog = catalogRelease(PluginProvenance::Catalog, 'essentialsx', 'EssentialsX');
    $hangar = catalogRelease(PluginProvenance::Hangar, 'EssentialsX/EssentialsX', 'EssentialsX');
    $modrinth = catalogRelease(PluginProvenance::Modrinth, 'sodium-essentials', 'Sodium Essentials');

    $service = new UnifiedCatalogService([
        new FakePluginSource(PluginProvenance::Catalog, sourcePage(PluginProvenance::Catalog, [$catalog])),
        new FakePluginSource(PluginProvenance::Hangar, sourcePage(PluginProvenance::Hangar, [$hangar])),
        new FakePluginSource(PluginProvenance::Modrinth, sourcePage(PluginProvenance::Modrinth, [$modrinth])),
    ]);

    $page = $service->search(new PluginSearchQuery);

    expect($page->items)->toHaveCount(3);

    $badges = array_map(fn (PluginRelease $r) => $r->source()->value, $page->items);
    expect($badges)->toEqualCanonicalizing(['Catalog', 'Hangar', 'Modrinth']);

    foreach ($page->items as $item) {
        expect($item->sourceUrl)->not->toBeEmpty();
    }
});

it('deduplicates by EXACT source+projectId identity, never by display name — two distinct Hangar projects sharing a name both survive', function () {
    $projectA = catalogRelease(PluginProvenance::Hangar, 'AuthorOne/Essentials', 'Essentials');
    $projectB = catalogRelease(PluginProvenance::Hangar, 'AuthorTwo/Essentials', 'Essentials');

    $service = new UnifiedCatalogService([
        new FakePluginSource(PluginProvenance::Hangar, sourcePage(PluginProvenance::Hangar, [$projectA, $projectB])),
    ]);

    $page = $service->search(new PluginSearchQuery);

    expect($page->items)->toHaveCount(2);
    $projectIds = array_map(fn (PluginRelease $r) => $r->id->projectId, $page->items);
    expect($projectIds)->toEqualCanonicalizing(['AuthorOne/Essentials', 'AuthorTwo/Essentials']);
});

it('collapses an EXACT repeat of the same source+projectId identity into one entry', function () {
    $releaseA = catalogRelease(PluginProvenance::Hangar, 'EssentialsX/EssentialsX', 'EssentialsX');
    $releaseAAgain = catalogRelease(PluginProvenance::Hangar, 'EssentialsX/EssentialsX', 'EssentialsX');

    $service = new UnifiedCatalogService([
        new FakePluginSource(PluginProvenance::Hangar, sourcePage(PluginProvenance::Hangar, [$releaseA, $releaseAAgain])),
    ]);

    $page = $service->search(new PluginSearchQuery);

    expect($page->items)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Deterministic sort — no opaque popularity score
|--------------------------------------------------------------------------
*/

it('sorts compatible-with-query releases before unknown before incompatible', function () {
    $compatible = catalogRelease(PluginProvenance::Hangar, 'compatible-plugin', 'ZZZ Compatible', evidence: [
        new PluginCompatibilityEvidence('test', 'matches', true),
    ]);
    $unknown = catalogRelease(PluginProvenance::Hangar, 'unknown-plugin', 'AAA Unknown', evidence: []);
    $incompatible = catalogRelease(PluginProvenance::Hangar, 'incompatible-plugin', 'MMM Incompatible', evidence: [
        new PluginCompatibilityEvidence('test', 'mismatch', false),
    ]);

    $service = new UnifiedCatalogService([
        new FakePluginSource(PluginProvenance::Hangar, sourcePage(PluginProvenance::Hangar, [$incompatible, $unknown, $compatible])),
    ]);

    $page = $service->search(new PluginSearchQuery);

    expect(array_map(fn (PluginRelease $r) => $r->name, $page->items))
        ->toBe(['ZZZ Compatible', 'AAA Unknown', 'MMM Incompatible']);
});

it('ranks an already-installed plugin above an equally-compatible one that is not installed', function () {
    PluginInstallation::query()->create([
        'relative_path' => 'plugins/Vault.jar',
        'name' => 'Vault',
        'hard_dependencies' => [],
        'soft_dependencies' => [],
        'inspection_diagnostics' => [],
        'compatibility_evidence' => [],
        'enabled' => true,
        'provenance' => 'Manual',
    ]);

    $installed = catalogRelease(PluginProvenance::Hangar, 'milkbowl/vault', 'Vault');
    $notInstalled = catalogRelease(PluginProvenance::Hangar, 'someone/AlphaPlugin', 'AlphaPlugin');

    $service = new UnifiedCatalogService([
        new FakePluginSource(PluginProvenance::Hangar, sourcePage(PluginProvenance::Hangar, [$notInstalled, $installed])),
    ]);

    $page = $service->search(new PluginSearchQuery);

    expect(array_map(fn (PluginRelease $r) => $r->name, $page->items))->toBe(['Vault', 'AlphaPlugin']);
});

it('ranks CraftKeeper Catalog above Hangar above Modrinth when every other tier is tied (source trust)', function () {
    $modrinth = catalogRelease(PluginProvenance::Modrinth, 'plugin-z', 'SamePlugin');
    $hangar = catalogRelease(PluginProvenance::Hangar, 'plugin-z-hangar', 'SamePlugin');
    $catalog = catalogRelease(PluginProvenance::Catalog, 'plugin-z-catalog', 'SamePlugin');

    $service = new UnifiedCatalogService([
        new FakePluginSource(PluginProvenance::Modrinth, sourcePage(PluginProvenance::Modrinth, [$modrinth])),
        new FakePluginSource(PluginProvenance::Hangar, sourcePage(PluginProvenance::Hangar, [$hangar])),
        new FakePluginSource(PluginProvenance::Catalog, sourcePage(PluginProvenance::Catalog, [$catalog])),
    ]);

    $page = $service->search(new PluginSearchQuery);

    expect(array_map(fn (PluginRelease $r) => $r->source()->value, $page->items))
        ->toBe(['Catalog', 'Hangar', 'Modrinth']);
});

it('breaks a full tie deterministically by name then project id — never leaving an unstable order', function () {
    $b = catalogRelease(PluginProvenance::Hangar, 'owner/bbb', 'Bbb Plugin');
    $a = catalogRelease(PluginProvenance::Hangar, 'owner/aaa', 'Aaa Plugin');

    $service = new UnifiedCatalogService([
        new FakePluginSource(PluginProvenance::Hangar, sourcePage(PluginProvenance::Hangar, [$b, $a])),
    ]);

    $first = $service->search(new PluginSearchQuery);
    $second = $service->search(new PluginSearchQuery);

    expect(array_map(fn (PluginRelease $r) => $r->name, $first->items))->toBe(['Aaa Plugin', 'Bbb Plugin']);
    expect(array_map(fn (PluginRelease $r) => $r->name, $second->items))->toBe(array_map(fn (PluginRelease $r) => $r->name, $first->items));
});

/*
|--------------------------------------------------------------------------
| Per-source degradation isolation (unit-level, with fakes)
|--------------------------------------------------------------------------
*/

it('carries a labeled degraded PluginSourceResult for one failing source while still merging the others\' items', function () {
    $catalogItem = catalogRelease(PluginProvenance::Catalog, 'essentialsx', 'EssentialsX');
    $modrinthItem = catalogRelease(PluginProvenance::Modrinth, 'sodium-essentials', 'Sodium Essentials');

    $service = new UnifiedCatalogService([
        new FakePluginSource(PluginProvenance::Catalog, sourcePage(PluginProvenance::Catalog, [$catalogItem])),
        new FakePluginSource(PluginProvenance::Hangar, sourcePage(PluginProvenance::Hangar, [], degraded: true, message: 'Hangar is down')),
        new FakePluginSource(PluginProvenance::Modrinth, sourcePage(PluginProvenance::Modrinth, [$modrinthItem])),
    ]);

    $page = $service->search(new PluginSearchQuery);

    expect($page->items)->toHaveCount(2);

    $hangarResult = collect($page->sourceResults)->firstWhere('source', PluginProvenance::Hangar);
    expect($hangarResult->degraded)->toBeTrue()
        ->and($hangarResult->message)->toBe('Hangar is down');

    $catalogResult = collect($page->sourceResults)->firstWhere('source', PluginProvenance::Catalog);
    $modrinthResult = collect($page->sourceResults)->firstWhere('source', PluginProvenance::Modrinth);
    expect($catalogResult->degraded)->toBeFalse()
        ->and($modrinthResult->degraded)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| End-to-end source isolation with the REAL adapters (Http::fake) — this
| is the scenario the brief's step 5 names explicitly: one source down
| produces a labeled degraded result while the OTHER successful sources
| AND that failing source's own cached results remain available.
|--------------------------------------------------------------------------
*/

it('end-to-end: when Hangar goes down after having succeeded before, its stale cache still serves while CraftKeeper Catalog and Modrinth stay healthy', function () {
    // A single Http::fake() call for the whole test: calling Http::fake()
    // a second time mid-test does NOT replace earlier stubs — Laravel's
    // Factory::fake()/stubUrl() APPEND to the existing stub list, and
    // the FIRST-registered matching stub wins (PendingRequest::
    // buildStubHandler() takes ->filter()->first() in insertion order).
    // Hangar's changing-behavior-over-time is modeled with a
    // Http::sequence() instead: one success (consumed by the first
    // search() below) followed by enough 500s to cover every attempt of
    // the SECOND search()'s full retry budget (1 initial + 2 retries).
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/fixtures/catalog/craftkeeper/catalog-basic.json')), true),
            200,
        ),
        'hangar.papermc.io/api/v1/projects?*' => Http::sequence()
            ->push(json_decode(file_get_contents(base_path('tests/fixtures/catalog/hangar/search-projects.json')), true), 200)
            ->push(['message' => 'Internal Server Error'], 500)
            ->push(['message' => 'Internal Server Error'], 500)
            ->push(['message' => 'Internal Server Error'], 500),
        'api.modrinth.com/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/fixtures/catalog/modrinth/search.json')), true),
            200,
        ),
    ]);

    /** @var UnifiedCatalogService $service */
    $service = app(UnifiedCatalogService::class);

    // First call: every source succeeds and Hangar's page is cached.
    $firstPage = $service->search(new PluginSearchQuery);
    expect(collect($firstPage->sourceResults)->every(fn ($r) => ! $r->degraded))->toBeTrue();

    $hangarItemsBefore = collect($firstPage->items)->filter(fn (PluginRelease $r) => $r->source() === PluginProvenance::Hangar);
    expect($hangarItemsBefore)->toHaveCount(2);

    // Move past the 15-minute freshness window so the next search()
    // attempts a live fetch again — which will now exhaust the queued
    // 500s above (retries included) and fail.
    $this->travel(20)->minutes();

    $secondPage = $service->search(new PluginSearchQuery);

    $hangarResult = collect($secondPage->sourceResults)->firstWhere('source', PluginProvenance::Hangar);
    expect($hangarResult->degraded)->toBeTrue()
        ->and($hangarResult->servedFromCache)->toBeTrue()
        ->and($hangarResult->stale)->toBeTrue();

    $catalogResult = collect($secondPage->sourceResults)->firstWhere('source', PluginProvenance::Catalog);
    $modrinthResult = collect($secondPage->sourceResults)->firstWhere('source', PluginProvenance::Modrinth);
    expect($catalogResult->degraded)->toBeFalse()
        ->and($modrinthResult->degraded)->toBeFalse();

    // The whole search did NOT fail, and Hangar's stale cached items are
    // still present alongside the two healthy sources' fresh items.
    $bySource = collect($secondPage->items)->groupBy(fn (PluginRelease $r) => $r->source()->value);
    expect($bySource->get('Hangar', collect()))->toHaveCount(2)
        ->and($bySource->get('Catalog', collect()))->not->toBeEmpty()
        ->and($bySource->get('Modrinth', collect()))->not->toBeEmpty();
});

it('end-to-end: a source with no prior cache at all fails empty-but-labeled, never breaking the whole search', function () {
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/fixtures/catalog/craftkeeper/catalog-basic.json')), true),
            200,
        ),
        'hangar.papermc.io/*' => Http::failedConnection(),
        'api.modrinth.com/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/fixtures/catalog/modrinth/search.json')), true),
            200,
        ),
    ]);

    /** @var UnifiedCatalogService $service */
    $service = app(UnifiedCatalogService::class);

    $page = $service->search(new PluginSearchQuery);

    $hangarResult = collect($page->sourceResults)->firstWhere('source', PluginProvenance::Hangar);
    expect($hangarResult->degraded)->toBeTrue()
        ->and($hangarResult->servedFromCache)->toBeFalse();

    $bySource = collect($page->items)->groupBy(fn (PluginRelease $r) => $r->source()->value);
    expect($bySource->has('Hangar'))->toBeFalse()
        ->and($bySource->get('Catalog', collect()))->not->toBeEmpty()
        ->and($bySource->get('Modrinth', collect()))->not->toBeEmpty();
});
