/**
 * Shared shapes for Task 12's Activity feed — mirrors App\Http\
 * Controllers\ActivityController exactly. `sources` is deliberately a
 * fixed, complete vocabulary (Task 12's ambiguity resolution #5).
 * "ai-proposal", "api-call", and "mcp-call" originally produced no items
 * (Task 12 shipped before the AI/API/MCP actors existed) but now filter on
 * the operation's real `actor.origin` (App\Operations\OperationAuthor) —
 * see that controller's own docblock for how each of the three is wired.
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
