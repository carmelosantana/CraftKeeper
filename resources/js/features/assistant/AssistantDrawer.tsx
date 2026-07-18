import { useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ConversationThread } from '@/features/assistant/ConversationThread';
import type { AiConversationDTO, AssistantStatus } from '@/types/assistant';

/**
 * The contextual assistant drawer — opened from wherever the operator
 * currently is (a config file, a plugin, the console) via the AppShell's
 * "Ask" button (see resources/js/layouts/AppShell.tsx) or the command
 * palette. `contextConfigPath` is whatever config path the CURRENTLY
 * VIEWED page represents; App\Http\Controllers\AssistantController::
 * store() persists it onto the new conversation's `context_scope` so
 * App\Ai\ContextBuilder picks it up on every turn — the drawer inherits
 * context, it does not ask the operator to re-describe it.
 *
 * No mockup exists yet for this specific drawer variant (Design/handoff/
 * pages.json marks "contextual assistant drawer" as not-yet-mocked) — it
 * is composed here from the same primitives and the same
 * ConversationThread/PageState the full-page assistant uses, so the two
 * surfaces never show different content for the same conversation.
 * Exactly like the full page, this never gains its own approve/execute
 * control — proposals are shown via ApprovalPanel (through
 * ConversationThread), linking out to the normal review flow only.
 */
export interface AssistantDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    status: AssistantStatus;
    statusMessage: string | null;
    conversation: AiConversationDTO | null;
    contextConfigPath?: string | null;
    sending?: boolean;
    onStartConversation: (configPath?: string | null) => void;
    onSend: (message: string) => void;
}

export function AssistantDrawer({
    open,
    onOpenChange,
    status,
    statusMessage,
    conversation,
    contextConfigPath = null,
    sending = false,
    onStartConversation,
    onSend,
}: AssistantDrawerProps) {
    const [draft, setDraft] = useState('');

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                data-test="assistant-drawer"
                className="flex w-full flex-col gap-0 p-0 sm:max-w-[420px]"
                style={{
                    backgroundColor: 'var(--ck-surface)',
                    borderColor: 'var(--ck-border)',
                }}
            >
                <SheetHeader
                    className="border-b px-[16px] py-[14px]"
                    style={{ borderColor: 'var(--ck-border)' }}
                >
                    <SheetTitle>Assistant</SheetTitle>
                    <SheetDescription>
                        {contextConfigPath
                            ? `Context: ${contextConfigPath}`
                            : 'Ask about your server'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-auto px-[16px] py-[14px]">
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
                        {conversation === null ? (
                            <PageState
                                state="empty"
                                title="No conversation yet"
                                description="Start a conversation — it inherits what you're currently viewing."
                                action={
                                    <Button
                                        type="button"
                                        onClick={() =>
                                            onStartConversation(
                                                contextConfigPath,
                                            )
                                        }
                                        data-test="assistant-drawer-start"
                                    >
                                        Start a conversation
                                    </Button>
                                }
                            />
                        ) : (
                            <ConversationThread
                                messages={conversation.messages}
                            />
                        )}
                    </PageState>
                </div>

                {conversation !== null && status === 'ready' && (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (draft.trim() === '') {
                                return;
                            }

                            onSend(draft.trim());
                            setDraft('');
                        }}
                        className="flex items-center gap-[8px] border-t px-[16px] py-[12px]"
                        style={{ borderColor: 'var(--ck-border)' }}
                    >
                        <Input
                            value={draft}
                            onChange={(event) => setDraft(event.target.value)}
                            placeholder="Ask a question"
                            aria-label="Ask the assistant"
                            data-test="assistant-drawer-input"
                            disabled={sending}
                        />
                        <Button
                            type="submit"
                            disabled={sending || draft.trim() === ''}
                            data-test="assistant-drawer-send"
                        >
                            {sending ? 'Sending…' : 'Send'}
                        </Button>
                    </form>
                )}
            </SheetContent>
        </Sheet>
    );
}
