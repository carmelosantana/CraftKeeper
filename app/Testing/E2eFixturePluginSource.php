<?php

namespace App\Testing;

use App\Catalog\Data\PluginRelease;
use App\Catalog\Data\PluginReleaseId;
use App\Catalog\Data\PluginSearchPage;
use App\Catalog\Data\PluginSearchQuery;
use App\Catalog\Data\PluginSourceResult;
use App\Catalog\Exceptions\PluginReleaseNotFound;
use App\Catalog\PluginSource;
use App\Plugins\PluginProvenance;

/**
 * A fully controllable, same-origin catalog source used ONLY by the
 * Playwright e2e suite (tests/e2e/plugins.spec.ts) — registered into the
 * `catalog.source` container tag ONLY when App\Http\Controllers\
 * E2eResetController::allowed() is true (see App\Providers\
 * AppServiceProvider), the identical environment+flag guard that gates
 * every other e2e-only surface in this application. Never reachable in
 * production; harmless dead code otherwise (mirrors E2eResetController's
 * own precedent of always existing in app/ but never being wired up
 * outside that guard).
 *
 * Exists because Playwright drives a REAL running `php artisan serve`
 * process with no Http::fake() available, so demonstrating "a mismatched
 * download never reaches /minecraft" and "an update failure leaves the
 * installed artifact intact" through actual browser clicks needs a real,
 * deterministic, same-origin release to install/update — see
 * App\Testing\E2ePluginFixtures for the real (not mocked) jar bytes this
 * serves.
 *
 * search() always returns the GOOD 1.0.0 release (installable for real).
 * release() with an explicit "1.0.0" version resolves that same good
 * release; release() with a null version ("give me the latest") resolves
 * the 1.1.0 release whose real served bytes do NOT match its declared
 * checksum — the update-checksum-mismatch scenario the e2e spec drives
 * through the Show page's "Check catalog for updates" -> Discover ->
 * install flow.
 */
final class E2eFixturePluginSource implements PluginSource
{
    private const PROJECT_ID = 'e2e-fixture-plugin';

    public function key(): PluginProvenance
    {
        return PluginProvenance::Catalog;
    }

    public function search(PluginSearchQuery $query): PluginSearchPage
    {
        return new PluginSearchPage(
            items: [$this->goodRelease()],
            sourceResults: [PluginSourceResult::ok(PluginProvenance::Catalog, servedFromCache: false, stale: false)],
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    public function release(PluginReleaseId $id): PluginRelease
    {
        if ($id->projectId !== self::PROJECT_ID) {
            throw PluginReleaseNotFound::forId($id);
        }

        return match ($id->version) {
            E2ePluginFixtures::GOOD_VERSION => $this->goodRelease(),
            null => $this->latestBadHashRelease(),
            default => throw PluginReleaseNotFound::forId($id),
        };
    }

    private function goodRelease(): PluginRelease
    {
        $bytes = E2ePluginFixtures::goodJarBytes();

        return new PluginRelease(
            id: new PluginReleaseId(PluginProvenance::Catalog, self::PROJECT_ID, E2ePluginFixtures::GOOD_VERSION),
            slug: self::PROJECT_ID,
            name: E2ePluginFixtures::PLUGIN_NAME,
            description: 'An e2e-only fixture plugin used to prove the checksum gate and update-failure guarantee through the real UI.',
            projectUrl: 'https://example.test/e2e-fixture-plugin',
            sourceUrl: 'https://example.test/e2e-fixture-plugin',
            license: 'MIT',
            sourceRepository: null,
            version: E2ePluginFixtures::GOOD_VERSION,
            minecraftVersions: ['1.21.8'],
            platforms: ['paper'],
            dependencies: [],
            downloadUrl: url('/__e2e__/fixtures/plugins/'.E2ePluginFixtures::GOOD_VERSION.'.jar'),
            sha256: hash('sha256', $bytes),
            releasedAt: null,
            withdrawn: false,
            signature: null,
        );
    }

    /**
     * Real, valid 1.1.0 jar bytes ARE served at this download URL — only
     * the DECLARED checksum below is wrong, exactly mirroring a
     * compromised or misconfigured catalog entry. App\Plugins\
     * PluginDownloader's real streamed-hash comparison is what refuses
     * it, not a shortcut in this fixture.
     */
    private function latestBadHashRelease(): PluginRelease
    {
        return new PluginRelease(
            id: new PluginReleaseId(PluginProvenance::Catalog, self::PROJECT_ID, E2ePluginFixtures::LATEST_VERSION),
            slug: self::PROJECT_ID,
            name: E2ePluginFixtures::PLUGIN_NAME,
            description: 'An e2e-only fixture "latest" release with a deliberately mismatched checksum.',
            projectUrl: 'https://example.test/e2e-fixture-plugin',
            sourceUrl: 'https://example.test/e2e-fixture-plugin',
            license: 'MIT',
            sourceRepository: null,
            version: E2ePluginFixtures::LATEST_VERSION,
            minecraftVersions: ['1.21.8'],
            platforms: ['paper'],
            dependencies: [],
            downloadUrl: url('/__e2e__/fixtures/plugins/'.E2ePluginFixtures::LATEST_VERSION.'.jar'),
            sha256: E2ePluginFixtures::LATEST_DECLARED_SHA256,
            releasedAt: null,
            withdrawn: false,
            signature: null,
        );
    }
}
