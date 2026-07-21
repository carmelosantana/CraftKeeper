<?php

namespace App\Http\Middleware;

use App\Models\AiProviderConfiguration;
use App\Support\UmamiScript;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Task 20: a per-request-nonce Content-Security-Policy for every `web`
 * group response (Inertia pages, onboarding, the OAuth consent screen).
 * Deliberately NOT applied to the `api`/MCP groups — CSP governs how a
 * response behaves when rendered as (or embedded in) a browser document,
 * which a JSON API/MCP JSON-RPC response never is.
 *
 * `script-src 'self' 'nonce-{nonce}'` — every first-party script is either
 * an external same-origin `<script src="/build/...">` (already covered by
 * `'self'`, no nonce needed) or the ONE inline script
 * resources/views/app.blade.php carries (the dark-mode flash-prevention
 * snippet), which carries this request's nonce via `$cspNonce` (shared
 * into every view rendered this request — see below). Umami's tag
 * (App\Support\UmamiScript) is the one OTHER inline-HTML script source in
 * this app, rendered as a raw `<script defer src="...">` string the
 * blade template echoes directly — rather than threading the nonce
 * through that string, its origin is added to `script-src`'s host list
 * instead (an external `<script src>` matching an allowed host does not
 * need a nonce), exactly as UmamiScript::allowedOrigin()'s own docblock
 * anticipates. Its origin is ALSO added to `connect-src`, since Umami's
 * script makes its own same-origin tracking-beacon POST.
 *
 * `style-src 'self' 'unsafe-inline'` — this app's own React components
 * set inline styles via the DOM `style` PROPERTY (CSSOM property
 * assignment, e.g. `element.style.color = ...`), which CSP's `style-src`
 * does not gate at all (only literal `style="..."` markup/attributes and
 * `<style>` elements are). Sonner (resources/js/components/ui/sonner.tsx)
 * and Radix UI, however, inject their base stylesheet as a literal
 * `<style>` element via plain `document.createElement('style')` with no
 * nonce attribute — a strict nonce'd `style-src` would silently break
 * every toast/dialog/dropdown's styling. Inline STYLE injection is a far
 * lower-severity concern than inline SCRIPT injection (it cannot execute
 * code; at worst it enables CSS-based data exfiltration or UI redress,
 * both requiring an existing markup-injection bug to matter at all) — the
 * standard, defensible trade-off used industry-wide for apps built on
 * CSS-in-JS/portal-based UI kits is to keep `script-src` strictly
 * nonce'd while allowing `'unsafe-inline'` for `style-src` alone.
 *
 * `frame-ancestors 'none'` / `object-src 'none'` — this app is never
 * meant to be framed by anyone (own or third-party) and never embeds a
 * plugin/Flash/Java object.
 *
 * `connect-src` starts at `'self'` (same-origin Inertia/fetch/API calls)
 * and is expanded ONLY for the services actually configured right now:
 * the Reverb websocket origin (config/broadcasting.php, static — always
 * added, since Reverb is this app's own always-present realtime
 * transport), the currently-active AI provider's origin (Setting/Secret-
 * backed, so this is the one directive built from a database read), the
 * three catalog source origins (config/catalog.php, static), and Umami's
 * origin when enabled. Nothing else is ever added — an unconfigured
 * service contributes nothing rather than a guessed-at wildcard.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));

        // Shared into every view rendered this request (in particular
        // resources/views/app.blade.php, Inertia's root view) so the
        // one inline <script> in this app can carry a nonce that
        // matches the header below.
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            $this->buildPolicy($nonce, $request),
        );

        return $response;
    }

    private function buildPolicy(string $nonce, Request $request): string
    {
        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $connectSrc = ["'self'"];

        // Task 20: Umami/AI origin resolution both touch the database
        // (Setting/Secret). The health check (`/up`, deliberately still
        // routed through this middleware — see routes/web.php) must stay
        // reachable and gracefully degrade even when the database itself
        // is the thing that's broken/missing (App\Http\Controllers\
        // HealthController already handles that for its OWN response
        // body; this middleware runs on top of ANY response and must not
        // turn an already-handled degraded response into an unhandled
        // 500 of its own). Matches this app's standing rule that an
        // optional integration's own outage is never allowed to affect
        // anything else (see e.g. App\Support\UmamiScript's docblock) —
        // here extended to "the database being unreachable at all never
        // breaks header generation," by simply contributing no extra
        // origins rather than throwing.
        try {
            if ($umamiOrigin = app(UmamiScript::class)->allowedOrigin()) {
                $scriptSrc[] = $umamiOrigin;
                $connectSrc[] = $umamiOrigin;
            }
        } catch (Throwable) {
            // no extra origin
        }

        if ($reverbOrigin = $this->reverbOrigin()) {
            $connectSrc[] = $reverbOrigin;
        }

        if ($sameOrigin = $this->sameOriginWebsocket($request)) {
            $connectSrc[] = $sameOrigin;
        }

        try {
            if ($aiOrigin = $this->activeAiProviderOrigin()) {
                $connectSrc[] = $aiOrigin;
            }
        } catch (Throwable) {
            // no extra origin
        }

        foreach ($this->catalogOrigins() as $catalogOrigin) {
            $connectSrc[] = $catalogOrigin;
        }

        // Local-dev-only carve-out for Vite's own HMR dev server
        // (`npm run dev`, never used by the built/e2e-tested app —
        // `public/hot` only exists while that process is running).
        // `public/hot`'s own contents are exactly the dev server's base
        // URL, so this reads that instead of guessing a port.
        if (app()->environment('local') && ($viteDevOrigin = $this->viteDevServerOrigin())) {
            $scriptSrc[] = $viteDevOrigin;
            $connectSrc[] = $viteDevOrigin;
            $connectSrc[] = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $viteDevOrigin);
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            'script-src '.implode(' ', array_unique($scriptSrc)),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            'connect-src '.implode(' ', array_unique($connectSrc)),
            "form-action 'self'",
        ];

        return implode('; ', $directives).';';
    }

    /**
     * The Reverb websocket origin, derived from config/broadcasting.php
     * (itself sourced from the REVERB_HOST/PORT/SCHEME env vars — the
     * same values VITE_REVERB_* mirror into the frontend bundle, per
     * .env.example). Null when no host is configured at all (matches
     * this app's "Reverb is optional/can be absent in dev" reality —
     * resources/js/lib/echo.ts already handles a socket that can never
     * open).
     */
    /**
     * The websocket origin of THIS request, added only when Reverb is the
     * active broadcaster and a key exists.
     *
     * When the key is supplied at runtime (the meta tag in
     * resources/views/app.blade.php), the browser opens its socket against
     * the page's own origin, because nginx proxies the Pusher protocol's
     * `/app` path through to Reverb — see resources/js/lib/echo.ts. That
     * endpoint is not `reverbOrigin()` above, which reflects where the Reverb
     * process binds (127.0.0.1:8081 in the container) rather than anywhere a
     * browser can reach.
     *
     * CSP Level 3 says `'self'` already covers same-origin ws/wss, but that
     * was implemented late and inconsistently across browsers, and a
     * mistakenly blocked websocket surfaces as "realtime silently never
     * connects" — indistinguishable from a misconfiguration. Naming the
     * origin explicitly costs one directive entry and removes the ambiguity.
     */
    private function sameOriginWebsocket(Request $request): ?string
    {
        if (! $this->realtimeIsActive()) {
            return null;
        }

        $scheme = $request->isSecure() ? 'wss' : 'ws';

        return "{$scheme}://{$request->getHttpHost()}";
    }

    /**
     * Whether this installation is actually broadcasting over Reverb right
     * now — the same condition resources/views/app.blade.php uses to decide
     * whether to publish the app key to the browser. When it is false no
     * websocket origin is advertised at all, because nothing will open one.
     */
    private function realtimeIsActive(): bool
    {
        return config('broadcasting.default') === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'));
    }

    private function reverbOrigin(): ?string
    {
        // Gated on realtime actually being on. In the container image
        // REVERB_HOST/PORT describe an internal hop (127.0.0.1:8081, where
        // the app publishes to its own Reverb — see the Dockerfile), which no
        // browser can reach; advertising it while broadcasting is disabled
        // put a loopback origin in the policy that nothing would ever use.
        // It still matters for local development, where Reverb runs
        // standalone on its own host/port and those genuinely are the
        // browser's endpoint.
        if (! $this->realtimeIsActive()) {
            return null;
        }

        $config = config('broadcasting.connections.reverb.options', []);
        $host = $config['host'] ?? null;

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $scheme = ($config['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $port = $config['port'] ?? null;
        $portSuffix = $port !== null ? ':'.$port : '';

        return "{$scheme}://{$host}{$portSuffix}";
    }

    /**
     * The currently-ACTIVE AI provider's origin only (not both possible
     * providers) — App\Models\AiProviderConfiguration::load() already
     * resolves which one is active; an unconfigured/inactive provider
     * contributes nothing. The browser never calls this origin directly
     * (App\Ai\AssistantService is a server-side HTTP client), but it is
     * listed anyway per this task's explicit requirement, as
     * defense-in-depth against any future client-side AI transport.
     */
    private function activeAiProviderOrigin(): ?string
    {
        $config = AiProviderConfiguration::load();

        if (! $config->isConfigured()) {
            return null;
        }

        $baseUrl = $config->activeProvider === 'ollama'
            ? $config->ollamaBaseUrl
            : $config->hostedBaseUrl;

        return $this->originOf($baseUrl);
    }

    /**
     * @return list<string>
     */
    private function catalogOrigins(): array
    {
        $urls = [
            config('catalog.sources.craftkeeper.url'),
            config('catalog.sources.hangar.base_url'),
            config('catalog.sources.modrinth.base_url'),
        ];

        return array_values(array_filter(array_map(
            fn ($url) => $this->originOf($url),
            $urls,
        )));
    }

    private function originOf(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}";
    }

    private function viteDevServerOrigin(): ?string
    {
        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        $contents = trim((string) file_get_contents($hotFile));
        $parts = parse_url($contents);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}";
    }
}
