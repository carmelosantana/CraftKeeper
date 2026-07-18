/**
 * Shared shapes for Task 15's plugin management UI — mirrors exactly what
 * App\Http\Controllers\PluginController builds for Inertia. One file so
 * Index/Discover/Show/Upload/Operation and the shared CompatibilityEvidence
 * feature agree on one vocabulary instead of drifting (same rationale as
 * resources/js/types/config.ts, Task 9).
 */

import type { OperationLifecycleStatus, OperationSummaryDTO } from '@/types/server';

export type PluginCompatibilityStateValue = 'compatible' | 'incompatible' | 'unknown' | 'warning';

export interface CompatibilityEvidenceEntryDTO {
    source: string;
    summary: string;
    supportsCompatibility: boolean | null;
}

export interface InspectionDiagnosticDTO {
    issue: string;
    message: string;
}

export interface PendingOperationSummaryDTO extends OperationSummaryDTO {}

export interface PluginInstallationDTO {
    relativePath: string;
    filename: string;
    name: string | null;
    version: string | null;
    mainClass: string | null;
    apiVersion: string | null;
    hardDependencies: string[];
    softDependencies: string[];
    sha256: string | null;
    sizeBytes: number | null;
    enabled: boolean;
    provenance: string;
    duplicateName: boolean;
    compatibilityState: PluginCompatibilityStateValue | null;
    compatibilityEvidence: CompatibilityEvidenceEntryDTO[];
    missingSince: string | null;
    lastSeenAt: string | null;
    pendingOperation: PendingOperationSummaryDTO | null;
}

export interface PluginIndexProps {
    plugins: PluginInstallationDTO[];
}

export interface RollbackArtifactDTO {
    id: number;
    sha256: string;
    sizeBytes: number;
    reason: string;
    createdAt: string | null;
}

export interface PluginShowProps {
    plugin: PluginInstallationDTO;
    history: OperationSummaryDTO[];
    rollbackArtifacts: RollbackArtifactDTO[];
}

export interface CatalogReleaseDTO {
    source: string;
    projectId: string;
    version: string;
    name: string;
    slug: string;
    description: string;
    license: string | null;
    projectUrl: string;
    minecraftVersions: string[];
    platforms: string[];
    withdrawn: boolean;
    installed: boolean;
    compatibilityEvidence: CompatibilityEvidenceEntryDTO[];
}

export interface CatalogSourceResultDTO {
    source: string;
    degraded: boolean;
    message: string | null;
    servedFromCache: boolean;
    stale: boolean;
}

export interface PluginDiscoverProps {
    query: string;
    items: CatalogReleaseDTO[];
    sourceResults: CatalogSourceResultDTO[];
}

export interface UploadFindingsDTO {
    token: string;
    sha256: string;
    sizeBytes: number;
    name: string | null;
    version: string | null;
    mainClass: string | null;
    apiVersion: string | null;
    hardDependencies: string[];
    softDependencies: string[];
    metadataSource: string | null;
    diagnostics: InspectionDiagnosticDTO[];
    existingInstallationPath: string | null;
}

export interface PluginUploadProps {
    findings: UploadFindingsDTO | null;
    error: string | null;
}

export interface PluginInstallPlanDTO {
    artifact: {
        name: string | null;
        version: string | null;
        mainClass: string | null;
        apiVersion: string | null;
        metadataSource?: string | null;
    };
    source?: string;
    checksum?: string;
    sizeBytes?: number;
    compatibility?: {
        state: PluginCompatibilityStateValue;
        evidence: CompatibilityEvidenceEntryDTO[];
    };
    dependencies?: { hard: string[]; soft: string[] };
    unmetHardDependencies?: string[];
    inspectionDiagnostics?: InspectionDiagnosticDTO[];
    fileChanges: string[];
    restartRequired: boolean;
    restoringArtifactId?: number;
    restoringSha256?: string;
    restoringReason?: string;
}

export interface PluginOperationProps {
    operation: OperationSummaryDTO;
    plan: PluginInstallPlanDTO | null;
    targetRelativePath: string | null;
    canRollback: boolean;
    restartObserved: boolean;
}

export type { OperationLifecycleStatus, OperationSummaryDTO };
