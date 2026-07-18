<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Blanket guarantee, for every test in the suite, that no HTTP
     * client call can ever reach the real network — an un-faked request
     * throws immediately instead of silently succeeding or hanging.
     * Task 14's catalog source tests are the first (and, as of this
     * task, only) code in the app that uses the HTTP client at all;
     * every one of them calls Http::fake() itself before touching a
     * source adapter, so this only ever matters as a safety net against
     * a future test that forgets to.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
