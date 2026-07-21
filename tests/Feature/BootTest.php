<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the CraftKeeper application', function () {
    // "/" is a router, not a page: on a fresh install it forwards to
    // onboarding. Follow the redirect so this still asserts what it was
    // written to assert — the app boots and serves CraftKeeper — rather
    // than the status code of the hop.
    $this->followingRedirects()->get('/')->assertOk()->assertSee('CraftKeeper');
});
