<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Task 19's optional Umami analytics tag. Disabled by default (no
 * `Setting` row means disabled, exactly like every other optional
 * integration in this application) and rendered ONLY when the operator has
 * explicitly enabled it AND supplied both a validated HTTPS script URL and
 * a website id — see `enabled()`. There is no partial/degraded state: a
 * misconfigured value (missing id, non-HTTPS URL, blank id) collapses to
 * "not rendered," never a broken or insecure tag.
 *
 * This is deliberately the ENTIRE integration: `resources/views/app.blade.php`
 * calls `tag()` directly and emits it as a literal `<script>` element. There
 * is no server-side proxy, no queued job, no outbound HTTP call from this
 * class or anywhere else in the backend — a load failure of the operator's
 * own Umami instance is therefore structurally invisible to CraftKeeper's
 * own health (`/up`, `ServerStatusService`, etc. never reference this
 * class), matching the task brief's "never block anything" requirement.
 * No analytics SDK/package is required for this — see composer.json/
 * package.json, neither of which gained a dependency for this feature.
 *
 * `allowedOrigin()` exists purely so a future CSP-emitting middleware
 * (Task 20) can permit exactly this one external origin instead of
 * loosening `script-src` to `*` or to Umami's origin unconditionally — the
 * CSP itself is out of scope here; this class only exposes what Task 20's
 * middleware needs to compute it.
 */
final class UmamiScript
{
    /**
     * True only when analytics.umami.enabled is truthy AND both a
     * validated HTTPS script URL and a non-blank website id are on file.
     * Every other combination (disabled, half-configured, an http:// URL,
     * a URL with no host) is treated as disabled — never rendered with a
     * missing piece filled in with a guess.
     */
    public function enabled(): bool
    {
        return $this->enabledSetting() && $this->scriptUrl() !== null && $this->websiteId() !== null;
    }

    /**
     * The raw operator opt-in flag, independent of whether the URL/id are
     * actually valid — lets App\Support\IntegrationHealthChecker
     * distinguish "Disabled" (the flag itself is off) from "Misconfigured"
     * (the flag is on, but the URL/id aren't usable) instead of collapsing
     * both into one `enabled()` boolean.
     */
    public function enabledSetting(): bool
    {
        return Setting::get('analytics.umami.enabled') === '1';
    }

    /**
     * The exact HTML to emit in <head> when enabled, or null otherwise.
     * `defer` is always present — this script must never block initial
     * page rendering. There is no `async`/inline fallback: a slow or
     * unreachable analytics host simply delays (never blocks, never
     * errors visibly to the operator) analytics collection alone.
     */
    public function tag(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return sprintf(
            '<script defer src="%s" data-website-id="%s"></script>',
            e((string) $this->scriptUrl()),
            e((string) $this->websiteId()),
        );
    }

    /**
     * The scheme+host(+port) of the configured script URL — the single
     * origin a future CSP `script-src`/`connect-src` directive should
     * allow when (and only when) Umami is actually enabled. Null whenever
     * `enabled()` is false, so a caller can never accidentally permit an
     * origin for a feature that isn't actually active.
     */
    public function allowedOrigin(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $parts = parse_url((string) $this->scriptUrl());

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
    }

    public function scriptUrl(): ?string
    {
        return $this->validatedHttpsUrl(Setting::get('analytics.umami.script_url'));
    }

    public function websiteId(): ?string
    {
        $raw = trim((string) Setting::get('analytics.umami.website_id'));

        return $raw === '' ? null : $raw;
    }

    /**
     * A URL is only accepted when it parses with an explicit "https"
     * scheme AND a non-empty host — this is the whole of the "validated
     * HTTPS script URL" requirement. Deliberately conservative: no
     * attempt is made to resolve DNS or reach the host (this class never
     * makes a network call at all), only to reject anything that isn't
     * even shaped like a real HTTPS URL.
     */
    private function validatedHttpsUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $trimmed = trim($url);
        $parts = parse_url($trimmed);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return null;
        }

        return $trimmed;
    }
}
