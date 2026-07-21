<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /dashboard was the Laravel starter kit's placeholder page. It is now only a
 * redirect to the real operations page, so what matters is that the chain
 * still ends somewhere correct — and, for a guest, never anywhere privileged.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_never_reach_the_overview_through_the_dashboard(): void
    {
        // A guest bounces /dashboard -> /overview -> /login. The redirect
        // itself carries no auth middleware, but /overview does, so the chain
        // cannot terminate on an authenticated page.
        $this->get(route('dashboard'))
            ->assertRedirect(route('overview', absolute: false));

        $this->get(route('overview'))
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
    }

    public function test_authenticated_users_are_sent_to_the_overview(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertRedirect(route('overview', absolute: false));
    }
}
