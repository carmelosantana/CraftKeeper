<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 20: the small set of security response headers that are always
 * correct regardless of request type (HTML page, Inertia partial, JSON
 * API response) and never depend on a database lookup — kept in a
 * separate, cheap, GLOBAL middleware (registered once in bootstrap/
 * app.php's global middleware list, applied to both the `web` and `api`
 * groups) rather than folded into ContentSecurityPolicy, which is `web`-
 * only and DOES touch the database (Umami/AI provider settings) to build
 * its directives.
 *
 * - `X-Content-Type-Options: nosniff` — stops a browser from
 *   MIME-sniffing a response into an executable type (e.g. a plugin JAR
 *   or uploaded config file served back with a wrong/missing
 *   Content-Type).
 * - `Referrer-Policy: strict-origin-when-cross-origin` — never leaks a
 *   full URL (which can contain a config path, plugin filename, or other
 *   operational detail) to a third-party origin; same-origin navigation
 *   still gets the full referrer.
 * - `Strict-Transport-Security` — ONLY set when the current request is
 *   actually secure (`$request->isSecure()`, which correctly reflects a
 *   trusted reverse proxy's `X-Forwarded-Proto` once TRUSTED_PROXIES is
 *   configured — see bootstrap/app.php). Sending HSTS over a plain HTTP
 *   response would be a no-op at best and actively wrong if the operator
 *   never intends to serve HTTPS at all.
 * - `X-Frame-Options: DENY` — a legacy companion to the CSP
 *   `frame-ancestors 'none'` directive (ContentSecurityPolicy) for the
 *   handful of older user agents that don't honor `frame-ancestors`.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
