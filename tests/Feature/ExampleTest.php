<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * "/" is a router, not a page. CraftKeeper is a control plane, so the
     * root URL renders no content of its own — it forwards to wherever the
     * visitor actually belongs. It previously served the Laravel starter
     * kit's stock welcome page, which meant the first screen an operator
     * saw was a Laravel splash rather than their server.
     */
    public function test_root_sends_a_fresh_install_to_onboarding(): void
    {
        $this->get(route('home'))
            ->assertRedirect(route('onboarding.welcome', absolute: false));
    }

    public function test_root_sends_a_signed_out_operator_to_login(): void
    {
        User::factory()->create();

        $this->get(route('home'))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_root_sends_a_signed_in_operator_to_the_overview(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))
            ->assertRedirect(route('overview', absolute: false));
    }

    /**
     * /dashboard was the starter kit's placeholder page and is the value
     * Fortify redirects to after login by default. It survives only as a
     * redirect so bookmarks and muscle memory reach the real thing.
     */
    public function test_dashboard_redirects_to_the_overview(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertRedirect('/overview');
    }
}
