<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Support\ApiScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The session-authenticated, web-only surface for managing /api/v1 scoped
 * tokens — Task 17's ambiguity resolution #7 (Integrations > API page).
 * This controller creates and revokes App\Models\User's Sanctum personal
 * access tokens; it never itself calls any /api/v1 endpoint or touches
 * App\Operations\OperationService.
 *
 * Token values are shown exactly once: store() renders the SAME index
 * page directly (never a redirect) with one extra `newToken` prop
 * carrying the plaintext value (Laravel\Sanctum\NewAccessToken::
 * plainTextToken) for this ONE response only. Sanctum only ever persists
 * the sha256 hash (see HasApiTokens::createToken()), so there is no
 * later request — a reload, a revisit, anything — on which the plaintext
 * value could ever reappear.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('integrations/Api', $this->props($request));
    }

    public function store(Request $request): Response|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(ApiScope::values())],
        ]);

        $token = $request->user()->createToken($data['name'], array_values(array_unique($data['scopes'])));

        return Inertia::render('integrations/Api', [
            ...$this->props($request),
            'newToken' => [
                'plainText' => $token->plainTextToken,
                'name' => $token->accessToken->name,
            ],
        ]);
    }

    public function destroy(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        abort_unless(
            $token->tokenable_id === $request->user()->getKey() && $token->tokenable_type === get_class($request->user()),
            404,
        );

        $token->delete();

        Inertia::flash('toast', ['type' => 'info', 'message' => 'API token revoked.']);

        return redirect('/integrations/api');
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Request $request): array
    {
        $tokens = $request->user()->tokens()->latest()->get();

        return [
            'tokens' => $tokens->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'createdAt' => $token->created_at?->toIso8601String(),
            ])->values(),
            'availableScopes' => array_map(fn (ApiScope $scope) => [
                'value' => $scope->value,
                'label' => $scope->label(),
            ], ApiScope::cases()),
            'openApiUrl' => '/openapi.yaml',
        ];
    }
}
