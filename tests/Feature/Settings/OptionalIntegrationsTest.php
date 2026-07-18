<?php

use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create();
});

/*
|--------------------------------------------------------------------------
| Task 19's own Step 1 test, verbatim.
|--------------------------------------------------------------------------
|
| Umami is disabled by default (no Setting row at all) and this test also
| covers the explicit "disabled" write. Either way, NOTHING about Umami —
| not a <script> tag, not a data-website-id attribute, not even a code
| comment — may appear anywhere in the rendered page.
*/
it('renders no analytics request when Umami is disabled', function () {
    Setting::put('analytics.umami.enabled', false);

    $this->actingAs($this->admin)->get('/overview')
        ->assertOk()
        ->assertDontSee('umami', false);
});

it('renders no analytics request when Umami has never been configured at all', function () {
    $this->actingAs($this->admin)->get('/overview')
        ->assertOk()
        ->assertDontSee('umami', false);
});

it('renders no analytics request when Umami is enabled but missing a script URL or website id', function () {
    Setting::put('analytics.umami.enabled', true);
    // No script_url, no website_id at all.

    $this->actingAs($this->admin)->get('/overview')
        ->assertOk()
        ->assertDontSee('umami', false);
});

it('renders no analytics request when the configured Umami script URL is not HTTPS', function () {
    Setting::put('analytics.umami.enabled', true);
    Setting::put('analytics.umami.script_url', 'http://analytics.example.com/script.js');
    Setting::put('analytics.umami.website_id', 'abc-123');

    $this->actingAs($this->admin)->get('/overview')
        ->assertOk()
        ->assertDontSee('umami', false)
        // The literal, insecure host must not leak into the page either.
        ->assertDontSee('analytics.example.com', false);
});

/*
|--------------------------------------------------------------------------
| The positive path: only render when genuinely enabled + valid.
|--------------------------------------------------------------------------
*/
it('renders exactly one deferred Umami script tag pointing only at the configured origin when fully configured', function () {
    Setting::put('analytics.umami.enabled', true);
    Setting::put('analytics.umami.script_url', 'https://analytics.example.com/script.js');
    Setting::put('analytics.umami.website_id', 'abc-123-website-id');

    $response = $this->actingAs($this->admin)->get('/overview')->assertOk();

    $response->assertSee(
        '<script defer src="https://analytics.example.com/script.js" data-website-id="abc-123-website-id"></script>',
        false,
    );

    // Exactly one script tag — never duplicated, never proxied through a
    // CraftKeeper-owned URL.
    expect(substr_count($response->getContent(), '<script defer src="https://analytics.example.com/script.js"'))->toBe(1);
});

it('never proxies the Umami script through the CraftKeeper backend', function () {
    Setting::put('analytics.umami.enabled', true);
    Setting::put('analytics.umami.script_url', 'https://analytics.example.com/script.js');
    Setting::put('analytics.umami.website_id', 'abc-123-website-id');

    $response = $this->actingAs($this->admin)->get('/overview')->assertOk();

    // The script src must be the operator's own origin verbatim, never
    // something like "/umami/script.js" or "/integrations/umami/proxy".
    expect($response->getContent())->not->toContain('/umami/');
});
