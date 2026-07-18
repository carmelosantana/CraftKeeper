/**
 * Shared shapes for Task 12's Activity feed — mirrors App\Http\
 * Controllers\ActivityController exactly. `sources` is deliberately a
 * fixed, complete vocabulary (Task 12's ambiguity resolution #5) even
 * though several of them ("ai-proposal", "api-call", "mcp-call") never
 * produce an item yet — see that controller's own docblock.
 */

export interface ActivityActorDTO {
    type: string;
    id: string | null;
    origin: string | null;
}

export interface ActivityItemDTO {
    id: string;
    source: string;
    actor: ActivityActorDTO;
    timestamp: string | null;
    status: string;
    /** Already redacted server-side — never a raw secret value. */
    summary: string;
    correlationId: string | null;
}

export interface ActivityFiltersDTO {
    source: string | null;
    status: string | null;
    q: string | null;
    from: string | null;
    to: string | null;
}

export interface ActivityProps {
    filters: ActivityFiltersDTO;
    sources: string[];
    items: ActivityItemDTO[];
}
