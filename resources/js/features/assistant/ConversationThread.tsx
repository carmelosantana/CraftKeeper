import { ApprovalPanel } from '@/features/assistant/ApprovalPanel';
import { RedactionDisclosure } from '@/features/assistant/RedactionDisclosure';
import { cn } from '@/lib/utils';
import type { AiMessageDTO } from '@/types/assistant';

/**
 * The message list shared by the full-page assistant
 * (resources/js/pages/Assistant.tsx) and the contextual drawer
 * (resources/js/features/assistant/AssistantDrawer.tsx) — one rendering
 * of App\Models\AiMessage rows, so the two surfaces can never drift.
 *
 * User bubbles are right-aligned and accent-filled; assistant turns have
 * no bubble background (matching the Assistant mockup) and surface
 * citations, any tool-proposed operation via ApprovalPanel (never an
 * inline approve control of its own), and a per-turn redaction
 * disclosure. An empty conversation renders nothing here — the page/
 * drawer itself owns the empty-state copy.
 */
export interface ConversationThreadProps {
    messages: AiMessageDTO[];
    ollamaAllowUnredacted?: boolean;
    className?: string;
}

function MessageBubble({ message }: { message: AiMessageDTO }) {
    if (message.role === 'user') {
        return (
            <div
                data-test="assistant-message-user"
                className="ml-auto max-w-[80%] rounded-[12px] rounded-br-[3px] px-[14px] py-[10px] text-[13px] leading-[1.5]"
                style={{
                    backgroundColor: 'var(--ck-accent)',
                    color: 'var(--ck-accent-fg)',
                }}
            >
                {message.content}
            </div>
        );
    }

    const toolResults = message.toolCalls.filter(
        (call) =>
            call.phase === 'result' &&
            (call.name === 'propose_config_change' ||
                call.name === 'compose_rcon_command'),
    );

    return (
        <div
            data-test="assistant-message-assistant"
            className="flex max-w-[92%] flex-col gap-[10px]"
        >
            <div className="flex items-center gap-[8px]">
                <span
                    aria-hidden="true"
                    className="flex size-[22px] items-center justify-center rounded-[6px] text-[9px] font-bold"
                    style={{
                        backgroundColor:
                            'color-mix(in srgb, var(--ck-provenance-ai-provider) 22%, transparent)',
                        color: 'var(--ck-provenance-ai-provider)',
                    }}
                >
                    AI
                </span>
                <span
                    className="text-[11.5px] font-semibold"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    CraftKeeper Assistant
                </span>
            </div>

            {message.error ? (
                <p role="alert" style={{ color: 'var(--ck-danger)' }}>
                    {message.error}
                </p>
            ) : (
                <p
                    className="text-[13.5px] leading-[1.6] whitespace-pre-wrap"
                    style={{ color: 'var(--ck-text)' }}
                >
                    {message.content || '…'}
                </p>
            )}

            {message.citations.length > 0 && (
                <div className="flex flex-wrap gap-[6px]">
                    {message.citations.map((citation) => (
                        <a
                            key={citation.url}
                            href={citation.url}
                            target="_blank"
                            rel="noreferrer"
                            data-test="assistant-citation"
                            className="rounded-[5px] px-[9px] py-[4px] text-[11px] font-semibold"
                            style={{
                                backgroundColor:
                                    'color-mix(in srgb, #4fb3b3 14%, transparent)',
                                color: '#6fc5c5',
                            }}
                        >
                            {citation.title}
                        </a>
                    ))}
                </div>
            )}

            {toolResults.map((call, index) => (
                <ApprovalPanel key={`${call.name}-${index}`} toolCall={call} />
            ))}

            <RedactionDisclosure disclosures={message.redactionDisclosures} />
        </div>
    );
}

export function ConversationThread({
    messages,
    className,
}: ConversationThreadProps) {
    return (
        <div
            data-test="assistant-conversation-thread"
            className={cn('flex flex-col gap-[16px]', className)}
        >
            {messages.map((message) => (
                <MessageBubble key={message.id} message={message} />
            ))}
        </div>
    );
}
