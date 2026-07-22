<?php

namespace Tests\Feature;

use App\Models\ServerSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The application shell renders on every authenticated page, and until
 * 1.1.1 it rendered a design-system mock: server "Survival" at
 * "mc.example.net" running "Paper 1.21.4", status online, "3 / 40 online",
 * and an account menu claiming "TOTP on". None of it was ever real —
 * resources/js/layouts/AppShell.tsx carried those as component defaults and
 * not one of its 25 call sites passed anything else.
 *
 * These tests pin the replacement: real values, and null (never a
 * plausible-looking default) when CraftKeeper does not know.
 */
class ApplicationShellPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_shell_data_is_shared_with_guests(): void
    {
        // Onboarding renders no shell, and an unauthenticated request has
        // no business probing the Minecraft volume or server state.
        $this->get('/onboarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('shell', null));
    }

    public function test_player_count_is_unknown_rather_than_zero_when_rcon_is_unavailable(): void
    {
        $this->actingAs(User::factory()->create());

        // No ServerSample at all — the sampler has never run.
        $this->get('/overview')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shell.server.playersOnline', null)
                ->where('shell.server.playersMax', null)
                // NOT "offline": CraftKeeper cannot distinguish "the server
                // is down" from "I cannot reach RCON", so it claims neither.
                ->where('shell.server.status', 'unknown')
                ->etc()
            );
    }

    public function test_a_fresh_sample_is_reported_verbatim_including_a_genuine_zero(): void
    {
        $this->actingAs(User::factory()->create());

        // A real, current sample reporting an empty server. Zero here is a
        // MEASURED zero and must be shown as such — the "no fabricated
        // zero" rule forbids inventing one, not reporting a real one.
        ServerSample::query()->create([
            'sampled_at' => now(),
            'rcon_reachable' => true,
            'player_count' => 0,
            'player_names' => [],
        ]);

        $this->get('/overview')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shell.server.playersOnline', 0)
                ->where('shell.server.status', 'online')
                ->etc()
            );
    }

    public function test_two_factor_state_reflects_the_account_rather_than_claiming_it_is_on(): void
    {
        // The dangerous half of the old mock: DEFAULT_USER hard-coded
        // totpEnabled: true, so every operator was told two-factor was
        // active whether or not they had ever set it up.
        $this->actingAs(User::factory()->create(['two_factor_confirmed_at' => null]));

        $this->get('/overview')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shell.user.totpEnabled', false)
                ->etc()
            );
    }

    public function test_confirmed_two_factor_is_reported_as_on(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_confirmed_at' => now()]));

        $this->get('/overview')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shell.user.totpEnabled', true)
                ->etc()
            );
    }

    /*
     * Deliberately NOT tested here: that the mock strings ("Survival",
     * "mc.example.net", "Paper 1.21.4", "3 / 40 online") are absent from the
     * page. They never lived in the HTML — they were component defaults
     * compiled into the JS bundle, which is exactly why every server-side
     * suite stayed green while every install displayed them. An
     * assertDontSee() here would pass whether or not the bug existed, which
     * is worse than no test.
     *
     * That assertion belongs where the real bundle renders:
     * tests/e2e/server-operations.spec.ts, "the shell reports real server
     * state".
     */
}
