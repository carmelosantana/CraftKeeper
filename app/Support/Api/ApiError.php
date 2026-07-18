<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The one JSON error shape every /api/v1 response uses, per Task 17's
 * ambiguity resolution #4: {code, message, details, correlation_id} —
 * flat, top-level, no envelope. Used both by
 * App\Http\Middleware\EnsureApiScope (which returns a response directly,
 * without ever throwing) and by the exception-rendering closures
 * registered in bootstrap/app.php for everything else (auth failures,
 * validation, 404s, throttling, config conflicts).
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function response(Request $request, int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => $details,
            'correlation_id' => self::correlationId($request),
        ], $status);
    }

    /**
     * The correlation id App\Http\Middleware\AssignApiCorrelationId already
     * attached to this request, or a freshly minted one for the rare case
     * an error occurs before that middleware ever ran (e.g. a malformed
     * request the router itself rejects).
     */
    public static function correlationId(Request $request): string
    {
        $existing = $request->attributes->get('correlation_id');

        return is_string($existing) && $existing !== '' ? $existing : (string) Str::uuid();
    }
}
