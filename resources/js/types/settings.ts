/**
 * Task 19's Settings index and the four sections that had no page before
 * this task — mirrors exactly what App\Http\Controllers\SettingsController
 * and App\Http\Controllers\BackupController build for Inertia.
 */

export interface SettingsSectionDTO {
    key: string;
    label: string;
    description: string;
    href: string;
}

export interface SettingsSummaryDTO {
    aiConfigured: boolean;
    analyticsActive: boolean;
    apiTokenCount: number;
    mcpGrantCount: number;
}

export interface SettingsIndexPageProps {
    sections: SettingsSectionDTO[];
    summary: SettingsSummaryDTO;
}

export interface ServerSettingsPageProps {
    minecraftPath: string | null;
    rconHost: string | null;
    rconPort: string | null;
    rconPasswordConfigured: boolean;
}

export interface AiSettingsPageProps {
    provider: string | null;
    hostedBaseUrl: string | null;
    hostedModel: string | null;
    hostedApiKeyConfigured: boolean;
    ollamaBaseUrl: string | null;
    ollamaModel: string | null;
    ollamaAllowUnredacted: boolean;
}

export interface AnalyticsSettingsPageProps {
    enabled: boolean;
    scriptUrl: string | null;
    websiteId: string | null;
    /** True only when App\Support\UmamiScript::enabled() is true — i.e.
     * the flag is on AND the URL/id are both actually valid. */
    active: boolean;
    allowedOrigin: string | null;
}

export interface AdvancedSettingsPageProps {
    dataRoot: string;
    minecraftRoot: string;
    phpVersion: string;
    laravelVersion: string;
}

export interface BackupDTO {
    name: string;
    sizeBytes: number;
    createdAt: string;
}

export interface BackupsSettingsPageProps {
    backups: BackupDTO[];
}
