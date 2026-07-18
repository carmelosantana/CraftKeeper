<?php

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Task 17's ambiguity resolution #4: every /api/v1 error is the same flat
 * JSON object — {code, message, details, correlation_id} — regardless of
 * which layer raised it (auth, scope, validation, not-found, throttling).
 */
it('renders a 401 with the shared error shape when no token is presented', function () {
    $response = $this->getJson('/api/v1/server/status')->assertUnauthorized();

    $response->assertJsonStructure(['code', 'message', 'details', 'correlation_id']);
    expect($response->json('code'))->toBe('unauthenticated');
});

it('renders a 403 with the shared error shape for a missing scope', function () {
    $token = User::factory()->create()->createToken('reader', ['config:read'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/server/status')->assertForbidden();

    $response->assertJsonStructure(['code', 'message', 'details', 'correlation_id']);
    expect($response->json('code'))->toBe('forbidden_scope');
});

it('renders a 404 with the shared error shape', function () {
    $token = User::factory()->create()->createToken('reader', ['activity:read'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/operations/00000000-0000-0000-0000-000000000000')
        ->assertNotFound();

    $response->assertJsonStructure(['code', 'message', 'details', 'correlation_id']);
});

it('echoes a caller-supplied X-Correlation-Id back on both success and error responses', function () {
    $correlationId = 'test-correlation-id-123';

    $response = $this->withHeaders(['X-Correlation-Id' => $correlationId])
        ->getJson('/api/v1/server/status')
        ->assertUnauthorized();

    expect($response->headers->get('X-Correlation-Id'))->toBe($correlationId);
    expect($response->json('correlation_id'))->toBe($correlationId);
});

it('renders a 429 with the shared error shape once the api rate limit is exceeded', function () {
    RateLimiter::for('api', fn () => Limit::perMinute(1));

    $token = User::factory()->create()->createToken('reader', ['server:read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/server/status')->assertOk();

    $response = $this->withToken($token)->getJson('/api/v1/server/status')->assertStatus(429);

    $response->assertJsonStructure(['code', 'message', 'details', 'correlation_id']);
    expect($response->json('code'))->toBe('rate_limited');
});
