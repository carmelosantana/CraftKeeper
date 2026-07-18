<?php

namespace App\Catalog\Transport;

use App\Catalog\Exceptions\PluginSourceHttpError;
use App\Catalog\Exceptions\PluginSourceResponseTooLarge;
use App\Catalog\Exceptions\PluginSourceTimeout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one shared GET path every App\Catalog\Sources adapter goes
 * through — implements the brief's "resilient HTTP source clients"
 * requirement in exactly one place so it is enforced identically for
 * CraftKeeper Catalog, Hangar, and Modrinth rather than re-implemented
 * (and possibly drifting) three times:
 *
 * - 5s connect timeout, 15s total request timeout (config('catalog.http')).
 * - Exactly two retries, ONLY for idempotent transient failures: a
 *   connection-level failure/timeout (ConnectionException), or a 5xx
 *   response. A 4xx is NEVER retried — see the `when` closure below,
 *   which only returns true for those two cases. `retry(..., throw:
 *   false)` means that even after retries are exhausted, a bad
 *   response comes back as an ordinary Response object (not a thrown
 *   exception) so this method can inspect it once and raise exactly
 *   one typed exception.
 * - An explicit, identifying User-Agent (never Guzzle's default).
 * - A response-size limit enforced by TWO independent checks — see
 *   App\Catalog\Exceptions\PluginSourceResponseTooLarge's docblock —
 *   mirroring App\Plugins\JarInspector's declared-size-then-actual-
 *   bytes defense from Task 13. Neither check ever decodes the body.
 * - ETag/Last-Modified are sent as conditional headers when the caller
 *   has a previously cached pair, and read back off every response so
 *   the caller can store them for next time; a 304 short-circuits
 *   straight to CatalogHttpResponse::notModified() before either
 *   size check runs (a 304 has no body to be oversized).
 */
final class CatalogHttpClient
{
    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $url, array $query, ?string $etag, ?string $lastModified): CatalogHttpResponse
    {
        $config = config('catalog.http');
        $headers = [];

        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }

        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        try {
            $response = Http::withUserAgent($config['user_agent'])
                ->withHeaders($headers)
                ->connectTimeout($config['connect_timeout_seconds'])
                ->timeout($config['timeout_seconds'])
                ->retry(
                    // Laravel's retry() `$times` is the TOTAL number of
                    // attempts, not the number of retries beyond the
                    // first — config('catalog.http.retries') is
                    // documented/named as "retries," so +1 here is what
                    // makes "2 retries" actually mean 3 total attempts
                    // (confirmed empirically: Http::fake() + assertSentCount).
                    $config['retries'] + 1,
                    $config['retry_delay_ms'],
                    when: fn (Throwable $exception): bool => $this->isIdempotentTransientFailure($exception),
                    throw: false,
                )
                ->get($url, $query);
        } catch (ConnectionException $exception) {
            throw PluginSourceTimeout::forUrl($url, $exception);
        }

        $responseEtag = $response->header('ETag') ?: null;
        $responseLastModified = $response->header('Last-Modified') ?: null;

        if ($response->status() === 304) {
            return CatalogHttpResponse::notModified($responseEtag ?? $etag, $responseLastModified ?? $lastModified);
        }

        $maxBytes = (int) $config['max_response_bytes'];
        // Response::header() (a thin wrapper over PSR-7's
        // getHeaderLine()) returns '' when the header is absent, never
        // null — so this is a plain emptiness check, not a null check.
        $declaredLength = $response->header('Content-Length');

        if ($declaredLength !== '' && (int) $declaredLength > $maxBytes) {
            throw PluginSourceResponseTooLarge::declared($url, (int) $declaredLength, $maxBytes);
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw PluginSourceResponseTooLarge::actual($url, strlen($body), $maxBytes);
        }

        if ($response->clientError()) {
            throw PluginSourceHttpError::client($url, $response->status());
        }

        if ($response->serverError()) {
            throw PluginSourceHttpError::server($url, $response->status());
        }

        return CatalogHttpResponse::ok($body, $responseEtag, $responseLastModified);
    }

    /**
     * True ONLY for a connection-level failure/timeout, or a 5xx
     * response — never for a 4xx. This is what makes the retry policy
     * "idempotent transient errors only": a 4xx means the request was
     * understood and rejected (retrying it verbatim would never
     * succeed), so it must return false here.
     */
    private function isIdempotentTransientFailure(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && $exception->response->serverError();
    }
}
