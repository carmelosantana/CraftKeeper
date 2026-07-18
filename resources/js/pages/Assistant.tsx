import { Head, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ConversationThread } from '@/features/assistant/ConversationThread';
import { useRealtimeStatus } from '@/hooks/use-realtime-status';
import { AppShell } from '@/layouts/AppShell';
import type { AssistantProps, AssistantStreamPayload } from '@/types/assistant';

const SUGGESTED_QUESTIONS = [
    'Is my whitelist enforced?',
    'Why did a plugin error on startup?',
    'Explain online-mode',
];

/**
 * Split out so useEcho (a hook, which cannot be called conditionally) is
 * only ever mounted once a real conversation id exists — the parent
 * conditionally renders this component instead of conditionally calling
 * the hook.
 */
function StreamListener({
    conversationId,
    onEvent,
}: {
    conversationId: string;
    onEvent: (payload: AssistantStreamPayload) => void;
}) {
    useEcho<AssistantStreamPayload>(
        `ai.conversations.${conversationId}`,
        '.assistant.stream',
        onEvent,
        [conversationId],
    );

    return null;
}

/**
 * The full-page AI assistant workspace — Design/CraftKeeper Assistant.dc.html.
 * Follows the design system's PageState primitives for the disabled/
 * unavailable states (both pre-built in resources/js/components/
 * craftkeeper/PageState.tsx: 'ai-disabled'/'ai-unavailable') so this page
 * is honest about WHY the assistant can't answer right now, and — per the
 * task brief's own test — always returns 200 and always renders SOME
 * content, never a 500, when AI is disabled or the configured provider is
 * unreachable.
 *
 * Sending a message is a normal POST/redirect/GET, matching every other
 * propose-style action in this app (App\Http\Controllers\
 * AssistantController::message()); the private `ai.conversations.{id}`
 * Reverb channel is used only to show a live "thinking…"/tool-progress
 * indicator WHILE that request is in flight, via useRealtimeStatus's
 * honest connecting/unavailable states for the interrupted-stream case.
 */
export default function Assistant({
    status,
    statusMessage,
    provider,
    ollamaAllowUnredacted,
    conversation,
}: AssistantProps) {
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [liveText, setLiveText] = useState('');
    const [liveTool, setLiveTool] = useState<string | null>(null);
    const realtime = useRealtimeStatus();

    function handleStreamEvent(payload: AssistantStreamPayload) {
        if (payload.kind === 'delta' && payload.text) {
            setLiveText((text) => text + payload.text);
        } else if (payload.kind === 'tool_call' && payload.name) {
            setLiveTool(payload.name);
        } else if (payload.kind === 'tool_result') {
            setLiveTool(null);
        }
    }

    function startConversation() {
        router.post(
            '/assistant/conversations',
            {},
            { preserveScroll: true },
        );
    }

    function send(message: string) {
        const trimmed = message.trim();

        if (trimmed === '' || !conversation) {
            return;
        }

        setLiveText('');
        setLiveTool(null);

        router.post(
            `/assistant/conversations/${conversation.id}/messages`,
            { message: trimmed },
            {
                preserveScroll: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onSuccess: () => setDraft(''),
            },
        );
    }

    return (
        <AppShell>
            <Head title="Assistant" />

            {conversation && (
                <StreamListener
                    conversationId={conversation.id}
                    onEvent={handleStreamEvent}
                />
            )}

            <header className="mb-[18px] flex flex-wrap items-center justify-between gap-[10px]">
                <div>
                    <h1 className="text-[20px] font-bold">Assistant</h1>
                    {status === 'ready' && provider && (
                        <p
                            className="text-[12px] font-medium"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            {provider} · {provider === 'ollama' ? 'local' : 'hosted'}
                        </p>
                    )}
                </div>
                {status === 'ready' && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={startConversation}
                        data-test="assistant-new-conversation"
                    >
                        + New conversation
                    </Button>
                )}
            </header>

            <PageState
                state={
                    status === 'disabled'
                        ? 'ai-disabled'
                        : status === 'unavailable'
                          ? 'ai-unavailable'
                          : 'normal'
                }
                title={
                    status === 'disabled'
                        ? 'AI is disabled'
                        : status === 'unavailable'
                          ? 'AI is unavailable'
                          : undefined
                }
                description={statusMessage ?? undefined}
            >
                <div className="grid gap-[20px] lg:grid-cols-[1fr_280px]">
                    <div className="flex flex-col gap-[16px]">
                        {conversation === null ? (
                            <PageState
                                state="empty"
                                title="No conversation yet"
                                description="Start a conversation to ask about your server, its configuration, or its logs."
                                action={
                                    <Button
                                        type="button"
                                        onClick={startConversation}
                                        data-test="assistant-start-conversation"
                                    >
                                        Start a conversation
                                    </Button>
                                }
                            />
                        ) : (
                            <>
                                {conversation.messages.length === 0 && (
                                    <p
                                        className="text-[12.5px]"
                                        style={{ color: 'var(--ck-text-2)' }}
                                    >
                                        Ask a question below to get started.
                                    </p>
                                )}
                                <ConversationThread
                                    messages={conversation.messages}
                                />

                                {sending && (
                                    <div
                                        role="status"
                                        data-test="assistant-streaming"
                                        className="rounded-[10px] border px-[14px] py-[10px] text-[12.5px]"
                                        style={{
                                            borderColor: 'var(--ck-border)',
                                            backgroundColor: 'var(--ck-surface)',
                                            color: 'var(--ck-text-2)',
                                        }}
                                    >
                                        {realtime === 'unavailable' && (
                                            <span data-test="assistant-interrupted-stream">
                                                Live updates unavailable — the
                                                answer will appear once it
                                                completes.{' '}
                                            </span>
                                        )}
                                        {liveTool
                                            ? `Running ${liveTool}…`
                                            : liveText || 'Thinking…'}
                                    </div>
                                )}

                                <div className="flex flex-wrap gap-[6px]">
                                    {SUGGESTED_QUESTIONS.map((question) => (
                                        <button
                                            key={question}
                                            type="button"
                                            onClick={() => send(question)}
                                            data-test="assistant-suggested-question"
                                            className="rounded-[999px] border px-[11px] py-[5px] text-[11.5px] font-medium"
                                            style={{
                                                borderColor: 'var(--ck-border-strong)',
                                                color: 'var(--ck-text-2)',
                                            }}
                                        >
                                            {question}
                                        </button>
                                    ))}
                                </div>

                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        send(draft);
                                    }}
                                    className="flex items-center gap-[8px]"
                                >
                                    <Input
                                        value={draft}
                                        onChange={(event) =>
                                            setDraft(event.target.value)
                                        }
                                        placeholder="Ask about your server — it can read the files you attach"
                                        aria-label="Ask the assistant"
                                        data-test="assistant-input"
                                        disabled={sending}
                                    />
                                    <Button
                                        type="submit"
                                        disabled={sending || draft.trim() === ''}
                                        data-test="assistant-send"
                                    >
                                        {sending ? 'Sending…' : 'Send'}
                                    </Button>
                                </form>
                            </>
                        )}
                    </div>

                    <aside
                        className="flex flex-col gap-[14px] rounded-[10px] border p-[14px]"
                        style={{
                            backgroundColor: 'var(--ck-surface)',
                            borderColor: 'var(--ck-border)',
                        }}
                    >
                        <div>
                            <h2
                                className="text-[11px] font-bold tracking-wide uppercase"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                Context sent to provider
                            </h2>
                            <p
                                className="mt-[6px] text-[12px] leading-[1.5]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                Server summary, the selected config file
                                (redacted), and recent audit history.
                            </p>
                        </div>

                        {provider === 'ollama' && ollamaAllowUnredacted && (
                            <div
                                role="status"
                                className="rounded-[7px] border px-[10px] py-[8px] text-[11.5px] leading-[1.5]"
                                style={{
                                    borderColor:
                                        'color-mix(in srgb, var(--ck-danger) 30%, var(--ck-border))',
                                    color: 'var(--ck-text)',
                                }}
                            >
                                Unredacted mode is enabled for local Ollama —
                                secret values are NOT masked before they are
                                sent.
                            </div>
                        )}

                        <div>
                            <h2
                                className="text-[11px] font-bold tracking-wide uppercase"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                Documentation sources
                            </h2>
                            <p
                                className="mt-[6px] text-[12px]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                Minecraft, Paper, Geyser, Floodgate, Hangar,
                                and Modrinth.
                            </p>
                        </div>
                    </aside>
                </div>
            </PageState>
        </AppShell>
    );
}
