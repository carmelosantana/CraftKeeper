/**
 * Shared shapes for Task 12's server operations workspace — mirrors
 * exactly what App\Http\Controllers\OverviewController/ServerController/
 * ConsoleController/LogController build for Inertia. One file so Overview,
 * the Server pages, and the Console/OperationProgress features agree on
 * one vocabulary instead of drifting (same rationale as
 * resources/js/types/config.ts, Task 9).
 */

export type CommandRiskLevel = 'safe' | 'elevated';

export type OperationLifecycleStatus =
    | 'proposed'
    | 'approved'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'rejected'
    | 'rolled_back';

export interface RconStatusDTO {
    available: boolean;
    reason: string | null;
    playerCount?: number | null;
    playerNames?: string[] | null;
    sampledAt?: string | null;
}

export interface LogStatusDTO {
    available: boolean;
    reason: string | null;
}

export interface OperationSummaryDTO {
    id: string;
    type: string;
    status: OperationLifecycleStatus;
    risk: 'standard' | 'elevated';
    target: string | null;
    actorType: string;
    actorId: string | null;
    actorOrigin: string | null;
    correlationId: string | null;
    createdAt: string | null;
    finishedAt: string | null;
    outcome: string | null;
    errorCode: string | null;
}

export interface OverviewProps {
    health: { rcon: RconStatusDTO; logs: LogStatusDTO };
    resources: { available: boolean; reason: string | null };
    pendingRestart: boolean;
    recentOperations: OperationSummaryDTO[];
    recentPlayerActivity: Array<{
        id: number;
        player: string | null;
        platform: string | null;
        kind: string;
        message: string | null;
        occurredAt: string | null;
    }>;
    attentionItems: Array<{ kind: string; message: string }>;
}

export interface ServerVersionDTO {
    known: boolean;
    label: string | null;
    source: 'jar' | 'log' | null;
    reason: string | null;
}

export interface PredefinedActionDTO {
    key: string;
    command: string;
    label: string;
    needsMessage: boolean;
    consequence: string;
}

export interface ServerIndexProps {
    rcon: RconStatusDTO;
    logs: LogStatusDTO;
    version: ServerVersionDTO;
    paths: { minecraftRoot: string; logFile: string };
    predefinedActions: PredefinedActionDTO[];
}

export interface PlayerDTO {
    /** The EXACT identity CraftKeeper has observed — a literal username
     * string, never a looked-up or fabricated Java/Bedrock UUID (Task
     * 11's ambiguity resolution #4; Task 12's own resolution #3). Any
     * player action must use this value as-is. */
    username: string;
    platform: 'java' | 'bedrock';
    firstSeenAt: string | null;
    lastSeenAt: string | null;
    /** null = unknown (RCON unavailable) — never fabricated to false. */
    online: boolean | null;
}

export interface ServerPlayersProps {
    rconAvailable: boolean;
    rconReason: string | null;
    players: PlayerDTO[];
}

export interface ConsoleEntryDTO {
    id: number;
    line: string;
    occurredAt: string | null;
}

export interface ComposePreviewDTO {
    command: string;
    normalizedCommand: string;
    risk: CommandRiskLevel;
    requiresApproval: boolean;
    consequence: string;
}

export interface PendingOperationDTO extends OperationSummaryDTO {
    consequence: string;
}

export interface ServerConsoleProps {
    rcon: { available: boolean; reason: string | null };
    logs: { available: boolean; reason: string | null };
    recentEntries: ConsoleEntryDTO[];
    predefinedActions: PredefinedActionDTO[];
    commandHistory: OperationSummaryDTO[];
    pendingOperation: PendingOperationDTO | null;
    composePreview: ComposePreviewDTO | null;
}

export type LogLevel = 'INFO' | 'WARN' | 'ERROR' | 'UNKNOWN';

export interface LogEntryDTO {
    id: number;
    line: string;
    occurredAt: string | null;
    level: LogLevel;
    player: string | null;
    source: string;
    matched: boolean;
}

export interface LogFiltersDTO {
    level: string | null;
    player: string | null;
    q: string | null;
    from: string | null;
    to: string | null;
    context: number;
}

export interface ServerLogsProps {
    logs: LogStatusDTO;
    filters: LogFiltersDTO;
    levels: string[];
    sources: string[];
    entries: LogEntryDTO[];
    truncated: boolean;
    totalMatched: number;
}
