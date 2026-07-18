<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\McpAuditEvent;
use App\Models\McpGrant;
use App\Support\ApiScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;

/**
 * The session-authenticated, web-only surface for managing MCP OAuth
 * integrations — Task 18's Integrations > MCP page. Creating an
 * integration provisions a REAL Laravel\Passport\Client (public,
 * authorization-code + PKCE only — see ClientRepository::
 * createAuthorizationCodeGrantClient()) and its paired App\Models\McpGrant
 * row in one step; there is no separate "register a client" flow and no
 * dynamic client registration endpoint anywhere in this application (Task
 * 18's ambiguity resolution #2). Revoking sets `revoked_at` on the
 * App\Models\McpGrant row (the authoritative kill switch every MCP
 * tool/resource call checks — App\Policies\McpGrantPolicy) AND revokes the
 * underlying Passport client/tokens, belt-and-suspenders.
 *
 * This controller NEVER itself calls into /mcp/craftkeeper or any MCP
 * tool/resource — it only manages the McpGrant/Client rows those calls are
 * later authorized against.
 */
class McpGrantController extends Controller
{
    private const RECENT_CALLS_LIMIT = 10;

    public function __construct(
        private readonly ClientRepository $clients,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('integrations/Mcp', $this->props());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'redirect_uri' => ['required', 'string', 'max:2048', 'url'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(ApiScope::values())],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $client = $this->clients->createAuthorizationCodeGrantClient(
            $data['display_name'],
            [$data['redirect_uri']],
            confidential: false,
        );

        McpGrant::query()->create([
            'oauth_client_id' => $client->id,
            'display_name' => $data['display_name'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'expires_at' => isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            'created_by' => $request->user()->getKey(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'MCP connection created.']);

        return redirect('/integrations/mcp');
    }

    public function destroy(Request $request, McpGrant $grant): RedirectResponse
    {
        $grant->forceFill(['revoked_at' => now()])->save();

        $client = Client::query()->find($grant->oauth_client_id);
        $client?->forceFill(['revoked' => true])->save();

        Token::query()->where('client_id', $grant->oauth_client_id)->update(['revoked' => true]);

        Inertia::flash('toast', ['type' => 'info', 'message' => 'MCP connection revoked.']);

        return redirect('/integrations/mcp');
    }

    /**
     * @return array<string, mixed>
     */
    private function props(): array
    {
        $grants = McpGrant::query()->latest()->get();

        return [
            'connectionUrl' => url('/mcp/craftkeeper'),
            'authorizationEndpoint' => route('passport.authorizations.authorize'),
            'tokenEndpoint' => route('passport.token'),
            'availableScopes' => array_map(fn (ApiScope $scope) => [
                'value' => $scope->value,
                'label' => $scope->label(),
            ], ApiScope::cases()),
            'grants' => $grants->map(fn (McpGrant $grant) => $this->mapGrant($grant))->values(),
        ];
    }

    /**
     * @return array{id: int, displayName: string, oauthClientId: string, scopes: list<string>, state: 'active'|'expired'|'revoked', expiresAt: string|null, revokedAt: string|null, lastUsedAt: string|null, createdAt: string|null, recentCalls: list<array{id: int, subjectType: string, subjectName: string, scope: string|null, outcome: string, denialReason: string|null, durationMs: int, correlationId: string, createdAt: string|null}>}
     */
    private function mapGrant(McpGrant $grant): array
    {
        $recentCalls = array_values(McpAuditEvent::query()
            ->where('mcp_grant_id', $grant->id)
            ->latest()
            ->limit(self::RECENT_CALLS_LIMIT)
            ->get()
            ->map(fn (McpAuditEvent $event) => $this->mapAuditEvent($event))
            ->all());

        return [
            'id' => $grant->id,
            'displayName' => $grant->display_name,
            'oauthClientId' => $grant->oauth_client_id,
            'scopes' => $grant->scopes,
            'state' => match (true) {
                $grant->isRevoked() => 'revoked',
                $grant->isExpired() => 'expired',
                default => 'active',
            },
            'expiresAt' => $grant->expires_at?->toIso8601String(),
            'revokedAt' => $grant->revoked_at?->toIso8601String(),
            'lastUsedAt' => $grant->last_used_at?->toIso8601String(),
            'createdAt' => $grant->created_at?->toIso8601String(),
            'recentCalls' => $recentCalls,
        ];
    }

    /**
     * @return array{id: int, subjectType: string, subjectName: string, scope: string|null, outcome: string, denialReason: string|null, durationMs: int, correlationId: string, createdAt: string|null}
     */
    private function mapAuditEvent(McpAuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'subjectType' => $event->subject_type,
            'subjectName' => $event->subject_name,
            'scope' => $event->scope,
            'outcome' => $event->outcome,
            'denialReason' => $event->denial_reason,
            'durationMs' => $event->duration_ms,
            'correlationId' => $event->correlation_id,
            'createdAt' => $event->created_at?->toIso8601String(),
        ];
    }
}
