<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Reverb app key reaches the browser at runtime, from the Inertia root
 * view, rather than through VITE_REVERB_APP_KEY.
 *
 * Vite inlines VITE_* at BUILD time, so the published image could only ever
 * carry whatever key existed on the machine that built it. Realtime was
 * therefore impossible in the published image regardless of how the operator
 * configured the container — see docs/architecture/decisions.md.
 */
class RealtimeClientConfigTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $this->actingAs(User::factory()->create());

        return $this;
    }

    public function test_the_key_is_published_when_reverb_is_the_active_broadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'a-real-app-key',
        ]);

        $this->actingAsAdmin()
            ->get('/overview')
            ->assertOk()
            ->assertSee('name="craftkeeper-reverb-key"', false)
            ->assertSee('a-real-app-key', false);
    }

    public function test_nothing_is_published_when_broadcasting_is_disabled(): void
    {
        // The container's own default: realtime off, so its absence is the
        // honest signal the frontend degrades on.
        config([
            'broadcasting.default' => 'log',
            'broadcasting.connections.reverb.key' => 'a-real-app-key',
        ]);

        $this->actingAsAdmin()
            ->get('/overview')
            ->assertOk()
            ->assertDontSee('craftkeeper-reverb-key', false)
            ->assertDontSee('a-real-app-key', false);
    }

    public function test_nothing_is_published_when_reverb_has_no_key(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => null,
        ]);

        $this->actingAsAdmin()
            ->get('/overview')
            ->assertOk()
            ->assertDontSee('craftkeeper-reverb-key', false);
    }

    /**
     * REVERB_APP_SECRET must never reach the browser. The key identifies a
     * websocket client (like a Pusher app key); the secret signs events and
     * is server-side only.
     */
    public function test_the_secret_is_never_published(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'a-real-app-key',
            'broadcasting.connections.reverb.secret' => 'super-secret-value',
        ]);

        $this->actingAsAdmin()
            ->get('/overview')
            ->assertOk()
            ->assertDontSee('super-secret-value', false);
    }

    /**
     * The browser connects to this page's own origin, because nginx proxies
     * the Pusher protocol's /app path through to Reverb. CSP has to allow it
     * or the socket is blocked and realtime silently never connects.
     */
    public function test_the_csp_allows_a_same_origin_websocket_when_realtime_is_on(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'a-real-app-key',
        ]);

        $csp = $this->actingAsAdmin()
            ->get('/overview')
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('ws://localhost', (string) $csp);
    }

    public function test_the_csp_adds_no_websocket_origin_when_realtime_is_off(): void
    {
        config([
            'broadcasting.default' => 'log',
            'broadcasting.connections.reverb.key' => 'a-real-app-key',
            'broadcasting.connections.reverb.options' => [],
        ]);

        $csp = (string) $this->actingAsAdmin()
            ->get('/overview')
            ->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('ws://', $csp);
        $this->assertStringNotContainsString('wss://', $csp);
    }
}
