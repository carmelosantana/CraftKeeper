<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a stable correlation id to every /api/v1 request, before
 * authentication or scope checks run, so App\Support\Api\ApiError can
 * always find one — including on a 401/403 short-circuit thrown by a
 * later middleware in the same stack (the request ATTRIBUTE survives
 * exception unwinding because it lives on the same Request instance the
 * exception handler receives). Echoes it back on every response
 * (success or error) as `X-Correlation-Id`, and honors a caller-supplied
 * `X-Correlation-Id` request header so a client's own trace id round-trips
 * unchanged rather than being silently replaced.
 */
class AssignApiCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Correlation-Id');
        $correlationId = is_string($incoming) && $incoming !== '' ? $incoming : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
