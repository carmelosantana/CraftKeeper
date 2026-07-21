<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * Host-header trust.
 *
 * Before this, any client-supplied Host was reflected into generated absolute
 * URLs, so a poisoned Host produced a working password-reset link pointing at
 * an attacker's domain.
 *
 * Note on approach: Illuminate\Http\Middleware\TrustHosts deliberately does
 * nothing while `runningUnitTests()` is true, so sending a request through the
 * HTTP kernel here would prove nothing about enforcement. These tests instead
 * exercise the two halves directly — which patterns are produced, and what
 * Symfony does with them — so both the policy and its effect are covered.
 */
class TrustedHostsTest extends TestCase
{
    protected function tearDown(): void
    {
        // Trusted hosts are static on Symfony's Request. Leaking them would
        // make unrelated tests fail depending on execution order.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function hostFor(string $hostHeader, array $patterns): string
    {
        Request::setTrustedHosts($patterns);

        return Request::create('http://'.$hostHeader.'/onboarding')->getHost();
    }

    public function test_an_unconfigured_install_does_not_enforce_anything(): void
    {
        // Laravel's own default. The operator has declared no hostname, so we
        // cannot know whether a LAN address or container name is legitimate —
        // and rejecting it would break a working install.
        config(['app.url' => 'http://localhost', 'craftkeeper.trusted_hosts' => null]);

        $this->assertSame([], AppServiceProvider::trustedHostPatterns());
    }

    /**
     * A bare "localhost" is the framework's default, not a statement about
     * where this install lives — and it is what compose.legendary.yml ships,
     * for a stack reached at http://localhost:8080 on the host running it.
     * Treating it as a declaration would enforce a loopback-only allowlist on
     * exactly the installs most likely to be reached by LAN address.
     */
    public function test_an_app_url_of_localhost_is_not_treated_as_a_declaration(): void
    {
        config(['app.url' => 'http://localhost:8080', 'craftkeeper.trusted_hosts' => null]);

        $this->assertSame([], AppServiceProvider::trustedHostPatterns());
    }

    public function test_setting_app_url_turns_enforcement_on(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        $this->assertNotEmpty(AppServiceProvider::trustedHostPatterns());
    }

    public function test_the_app_url_host_is_accepted(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        $this->assertSame(
            'craftkeeper.example.com',
            $this->hostFor('craftkeeper.example.com', AppServiceProvider::trustedHostPatterns()),
        );
    }

    public function test_a_foreign_host_is_rejected(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        $this->expectException(SuspiciousOperationException::class);

        $this->hostFor('evil.example.com', AppServiceProvider::trustedHostPatterns());
    }

    /**
     * The exact payload observed against the running container on 2026-07-21.
     */
    public function test_the_reported_payload_is_rejected(): void
    {
        config(['app.url' => 'http://127.0.0.1:8123', 'craftkeeper.trusted_hosts' => null]);

        $this->expectException(SuspiciousOperationException::class);

        $this->hostFor('evil.example.com', AppServiceProvider::trustedHostPatterns());
    }

    /**
     * Symfony wraps each pattern as `{pattern}i` and runs preg_match, so an
     * unanchored "localhost" would happily match a hostname an attacker owns.
     */
    public function test_patterns_are_anchored_against_suffix_and_prefix_attacks(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        $patterns = AppServiceProvider::trustedHostPatterns();

        foreach (['evil.localhost.example.com', 'craftkeeper.example.com.evil.test', 'notlocalhost'] as $host) {
            try {
                $this->hostFor($host, $patterns);
                $this->fail("Expected {$host} to be rejected.");
            } catch (SuspiciousOperationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Subdomains are not implied. A control plane answers on one name; if a
     * second is genuinely needed it goes in TRUSTED_HOSTS explicitly.
     */
    public function test_subdomains_of_the_app_url_are_not_implied(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        $this->expectException(SuspiciousOperationException::class);

        $this->hostFor('sub.craftkeeper.example.com', AppServiceProvider::trustedHostPatterns());
    }

    public function test_extra_hosts_can_be_declared(): void
    {
        config([
            'app.url' => 'https://craftkeeper.example.com',
            'craftkeeper.trusted_hosts' => 'craftkeeper.lan, 192.168.1.50',
        ]);

        $patterns = AppServiceProvider::trustedHostPatterns();

        $this->assertSame('craftkeeper.lan', $this->hostFor('craftkeeper.lan', $patterns));
        $this->assertSame('192.168.1.50', $this->hostFor('192.168.1.50', $patterns));
    }

    public function test_trusted_hosts_alone_turns_enforcement_on(): void
    {
        config(['app.url' => 'http://localhost', 'craftkeeper.trusted_hosts' => 'craftkeeper.lan']);

        $patterns = AppServiceProvider::trustedHostPatterns();

        $this->assertNotEmpty($patterns);
        $this->assertSame('craftkeeper.lan', $this->hostFor('craftkeeper.lan', $patterns));
    }

    /**
     * Loopback stays reachable whenever enforcement is on. These names cannot
     * carry a victim to an attacker — they resolve to the victim's own machine
     * — and `docker run -p 8123:8080` plus the image health check depend on it.
     */
    public function test_loopback_hostname_is_always_reachable(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        $this->assertSame(
            'localhost',
            $this->hostFor('localhost', AppServiceProvider::trustedHostPatterns()),
        );
    }

    public function test_loopback_address_is_always_reachable(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        $this->assertSame(
            '127.0.0.1',
            $this->hostFor('127.0.0.1', AppServiceProvider::trustedHostPatterns()),
        );
    }

    /**
     * The actual harm being prevented: an emailed reset link must never carry
     * a hostname the operator never declared.
     *
     * Laravel builds those links from the current request's root URL, so the
     * poisoned request is bound into the URL generator exactly as it would be
     * mid-request. Generation must fail closed rather than emit the host.
     */
    public function test_a_poisoned_host_cannot_reach_a_generated_password_reset_url(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        Request::setTrustedHosts(AppServiceProvider::trustedHostPatterns());

        $generator = $this->app['url'];
        $generator->setRequest(Request::create('http://evil.example.com/forgot-password'));

        try {
            $generated = $generator->to('/reset-password/token');
            $this->fail("Expected URL generation to refuse, produced: {$generated}");
        } catch (SuspiciousOperationException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * The same request is generated normally once the host is one the operator
     * actually declared — proving the test above fails for the right reason.
     */
    public function test_a_declared_host_still_generates_password_reset_urls(): void
    {
        config(['app.url' => 'https://craftkeeper.example.com', 'craftkeeper.trusted_hosts' => null]);

        Request::setTrustedHosts(AppServiceProvider::trustedHostPatterns());

        $generator = $this->app['url'];
        $generator->setRequest(Request::create('https://craftkeeper.example.com/forgot-password'));

        $this->assertSame(
            'https://craftkeeper.example.com/reset-password/token',
            $generator->to('/reset-password/token'),
        );
    }
}
