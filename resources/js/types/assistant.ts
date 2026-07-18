/**
 * Shared shapes for Task 16's AI assistant — mirrors exactly what
 * App\Http\Controllers\AssistantController builds for Inertia and what
 * App\Events\AiAssistantStreamEvent/AiMessageStreamed broadcast over the
 * conversation's private Reverb channel.
 */

export type AssistantStatus = 'ready' | 'disabled' | 'unavailable';

export interface CitationDTO {
    title: string;
    url: string;
    source?: string;
}

export interface ToolCallDTO {
    name: string;
    phase: 'call' | 'result';
    arguments?: Record<string, unknown>;
    status?: 'success' | 'error';
    summary?: string;
}

export interface RedactionDisclosureDTO {
    label: string | null;
    occurrences: number;
}

export interface AiMessageDTO {
    id: string;
    role: 'user' | 'assistant' | 'system' | 'tool';
    content: string;
    citations: CitationDTO[];
    toolCalls: ToolCallDTO[];
    redactionDisclosures: RedactionDisclosureDTO[];
    provider?: string | null;
    error: string | null;
    createdAt: string | null;
}

export interface AiConversationSummaryDTO {
    id: string;
    title: string;
    updatedAt: string | null;
}

export interface AiConversationDTO {
    id: string;
    title: string | null;
    contextScope: Record<string, unknown> | null;
    messages: AiMessageDTO[];
}

export interface AssistantProps {
    status: AssistantStatus;
    statusMessage: string | null;
    provider: string | null;
    ollamaAllowUnredacted: boolean;
    conversations: AiConversationSummaryDTO[];
    conversation: AiConversationDTO | null;
}

/** The shape a `propose_config_change`/`compose_rcon_command` tool result's
 * `summary` field JSON-decodes to — see App\Ai\Tools\*'s ToolResult::json()
 * payloads. Parsed client-side to link straight to the resulting
 * Operation's existing approval flow (never a new approve action). */
export interface ProposedOperationSummary {
    operation_id: string;
    status: string;
    risk?: string;
    path?: string;
    command?: string;
    explanation?: string;
    consequence?: string;
    message: string;
}

/** App\Events\AiAssistantStreamEvent's broadcastWith() payload. */
export interface AssistantStreamPayload {
    kind: 'delta' | 'tool_call' | 'tool_result';
    text?: string;
    name?: string;
    status?: 'success' | 'error';
}
