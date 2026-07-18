<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Every /api/v1 controller resolves its authenticated principal through
 * the 'sanctum' guard EXPLICITLY — never the bare $request->user(), which
 * resolves through config/auth.php's DEFAULT guard ('web', a session
 * guard that is never populated for a token-authenticated request, and
 * would silently return null). App\Http\Middleware\EnsureApiScope has
 * already proven a scoped Laravel\Sanctum\PersonalAccessToken is present
 * before any controller action runs; these helpers just give controllers
 * a safe, explicit way to read it back without risking a guard mismatch.
 */
trait ResolvesApiPrincipal
{
    protected function apiUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        return $user;
    }

    protected function apiToken(Request $request): PersonalAccessToken
    {
        /** @var PersonalAccessToken $token */
        $token = $this->apiUser($request)->currentAccessToken();

        return $token;
    }
}
