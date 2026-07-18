/**
 * Shared shapes for Task 17's Integrations > API page — mirrors exactly
 * what App\Http\Controllers\Integrations\ApiTokenController builds for
 * Inertia.
 */

export interface ApiScopeOptionDTO {
    value: string;
    label: string;
}

export interface ApiTokenDTO {
    id: number;
    name: string;
    abilities: string[];
    lastUsedAt: string | null;
    createdAt: string | null;
}

/**
 * Present ONLY on the single response immediately after a token is
 * created — see ApiTokenController::store()'s own docblock. Never
 * present on a normal GET /integrations/api load, and never
 * reconstructable from `ApiTokenDTO` (which never carries a plaintext
 * value at all).
 */
export interface NewApiTokenDTO {
    plainText: string;
    name: string;
}

export interface ApiIntegrationsPageProps {
    tokens: ApiTokenDTO[];
    availableScopes: ApiScopeOptionDTO[];
    openApiUrl: string;
    newToken?: NewApiTokenDTO | null;
}

/**
 * Task 18's Integrations > MCP page — mirrors exactly what
 * App\Http\Controllers\Integrations\McpGrantController builds for
 * Inertia. `state` is derived server-side from the same
 * revoked_at/expires_at fields App\Policies\McpGrantPolicy enforces
 * against, so the UI can never disagree with what the server actually
 * allows.
 */
export interface McpAuditEventDTO {
    id: number;
    subjectType: string;
    subjectName: string;
    scope: string | null;
    outcome: string;
    denialReason: string | null;
    durationMs: number;
    correlationId: string;
    createdAt: string | null;
}

export type McpGrantState = 'active' | 'revoked' | 'expired';

export interface McpGrantDTO {
    id: number;
    displayName: string;
    oauthClientId: string;
    scopes: string[];
    state: McpGrantState;
    expiresAt: string | null;
    revokedAt: string | null;
    lastUsedAt: string | null;
    createdAt: string | null;
    recentCalls: McpAuditEventDTO[];
}

export interface McpIntegrationsPageProps {
    connectionUrl: string;
    authorizationEndpoint: string;
    tokenEndpoint: string;
    availableScopes: ApiScopeOptionDTO[];
    grants: McpGrantDTO[];
}

/**
 * Task 19's Integrations overview — mirrors
 * App\Support\IntegrationStatus::toArray() exactly. `state` is always one
 * of exactly four values; every row also carries a distinct shape glyph
 * via StatusBadge, so state is never color-alone (see StatusBadge.tsx's
 * own docblock).
 */
export type IntegrationState = 'connected' | 'disabled' | 'degraded' | 'misconfigured';

export interface IntegrationStatusDTO {
    key: string;
    label: string;
    state: IntegrationState;
    reason: string | null;
    testable: boolean;
}

export interface IntegrationsPageProps {
    integrations: IntegrationStatusDTO[];
}
