/**
 * Shared shapes for the Task 9 configuration editor — mirrors exactly what
 * App\Http\Controllers\ConfigController builds for Inertia. Kept as one
 * file so the three edit modes (guided/structured/source) and the shared
 * DiffReview panel agree on one vocabulary instead of drifting.
 */

/** The sentinel every schema `secret: true` field's value is replaced with
 * before it ever reaches the browser — never the real value. Submitting
 * this literal string back for a secret field means "unchanged." */
export const SECRET_MASK = '••••••';

/** A plain JSON-shaped value — structurally a subtype of Inertia's own
 * `FormDataConvertible`, so guided/structured editor state can be posted
 * directly via `router.post()` without a manual cast at every call site. */
export type JsonValue =
    | string
    | number
    | boolean
    | null
    | JsonValue[]
    | { [key: string]: JsonValue };

export type ConfigFieldType = 'boolean' | 'integer' | 'number' | 'string' | 'array';
export type RestartImpact = 'none' | 'reload' | 'restart';
export type ConfigFieldRisk = 'low' | 'medium' | 'high';
export type OperationRiskLevel = 'standard' | 'elevated';
export type DiagnosticSeverity = 'error' | 'warning';
export type EditMode = 'guided' | 'structured' | 'source';

export interface DiagnosticDTO {
    severity: DiagnosticSeverity;
    message: string;
    path: string | null;
    line: number | null;
    column: number | null;
}

export interface GuidedFieldDTO {
    path: string;
    type: ConfigFieldType;
    title: string;
    description: string;
    default: unknown;
    restartImpact: RestartImpact;
    risk: ConfigFieldRisk;
    allowedValues: Array<string | number | boolean> | null;
    range: { min: number | null; max: number | null } | null;
    secret: boolean;
    documentationUrl: string;
    currentValue: unknown;
    advanced: boolean;
}

export interface GuidedGroupDTO {
    title: string;
    fields: GuidedFieldDTO[];
}

export interface ConfigFileMetaDTO {
    path: string;
    filename: string;
    format: string;
    category: string;
    provenance: string;
    recognized: boolean;
    schemaId: string | null;
    schemaTitle: string | null;
    modifiedAt: string;
    sizeBytes: number;
    baseSha256: string;
    validation: { valid: boolean; diagnostics: DiagnosticDTO[] };
}

export interface ProposalFieldDTO {
    path: string;
    summary: string;
    before: string | null;
    after: string | null;
}

export interface ProposalDTO {
    operationId: string;
    status:
        | 'proposed'
        | 'approved'
        | 'running'
        | 'succeeded'
        | 'failed'
        | 'rejected'
        | 'rolled_back';
    kind: 'apply' | 'restore';
    diff: string;
    valid: boolean;
    diagnostics: DiagnosticDTO[];
    restartImpact: RestartImpact;
    risk: OperationRiskLevel;
    documentation: Array<{ path: string; url: string }>;
    fields: ProposalFieldDTO[];
    normalizationWarning: boolean;
    expiresAt: string | null;
    outcome: string | null;
    errorCode: string | null;
}

export interface ConfigEditProps {
    file: ConfigFileMetaDTO;
    guided: { groups: GuidedGroupDTO[] } | null;
    structured: { data: Record<string, JsonValue> };
    source: { contents: string };
    proposal: ProposalDTO | null;
    historyUrl: string;
    sourceError?: string;
}

export interface InventoryItemDTO {
    path: string;
    filename: string;
    format: string;
    category: string;
    provenance: string;
    recognized: boolean;
    schemaTitle: string | null;
    pluginName: string | null;
    sizeBytes: number;
    modifiedAt: string | null;
    valid: boolean | null;
    preview: string | null;
    restartImpact: RestartImpact | null;
    readable: boolean;
}

export interface ConfigIndexProps {
    query: string;
    groups: Record<string, InventoryItemDTO[]>;
    total: number;
}

export interface ConflictProposedRowDTO {
    path: string;
    before: string;
    after: string;
}

export interface ConfigConflictProps {
    path: string;
    expectedSha256: string;
    actualSha256: string;
    base: string;
    disk: string;
    diskSha256: string;
    proposed: ConflictProposedRowDTO[];
    mode: EditMode;
}

export interface RevisionDTO {
    id: number;
    kind: string;
    summary: string | null;
    diff: string | null;
    restartImpact: string | null;
    risk: string | null;
    authorType: string | null;
    createdAt: string | null;
}

export interface ConfigHistoryProps {
    path: string;
    revisions: RevisionDTO[];
}
