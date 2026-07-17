<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the CraftKeeper application', function () {
    $this->get('/')->assertOk()->assertSee('CraftKeeper');
});
