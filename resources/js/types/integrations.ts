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
