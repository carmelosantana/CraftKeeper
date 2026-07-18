import { Head, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { StatusGlyph } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CommandComposer } from '@/features/console/CommandComposer';
import { useClipboard } from '@/hooks/use-clipboard';
import { useIsMobile } from '@/hooks/use-mobile';
import { useRealtimeStatus } from '@/hooks/use-realtime-status';
import { AppShell } from '@/layouts/AppShell';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import type { ConsoleEntryDTO, ServerConsoleProps } from '@/types/server';

interface ConsoleEntryReceivedPayload {
    id: number;
    line: string;
    occurred_at: string;
}

const RECONNECT_COPY: Record<string, string> = {
    connecting: 'Connecting to live console updates…',
    unavailable: 'Live console updates are unavailable — showing the last loaded lines.',
};

function ReconnectBanner() {
    const status = useRealtimeStatus();

    if (status === 'connected') {
        return null;
    }

    return (
        <div
            role="status"
            data-test="console-reconnect-banner"
            data-ck-realtime-status={status}
            className="flex items-center gap-[8px] rounded-[8px] border px-[12px] py-[8px] text-[12px] font-medium"
            style={ckSubtleSurfaceStyle('warning')}
        >
            <StatusGlyph tone="warning" glyph="ring" />
            {RECONNECT_COPY[status] ?? RECONNECT_COPY.unavailable}
        </div>
    );
}

export default function ServerConsole(props: ServerConsoleProps) {
    const {
        rcon,
        logs,
        recentEntries,
        predefinedActions,
        commandHistory,
        pendingOperation,
        composePreview,
    } = props;
    const isMobile = useIsMobile();

    const [entries, setEntries] = useState<ConsoleEntryDTO[]>(recentEntries);
    const [following, setFollowing] = useState(true);
    const [bufferedCount, setBufferedCount] = useState(0);
    const bufferRef = useRef<ConsoleEntryDTO[]>([]);
    const [filter, setFilter] = useState('');
    const [sayMessage, setSayMessage] = useState('');
    const [, copy] = useClipboard();
    const feedEndRef = useRef<HTMLDivElement>(null);

    useEcho<ConsoleEntryReceivedPayload>(
        'server.console',
        '.console.entry',
        (payload) => {
            const entry: ConsoleEntryDTO = {
                id: payload.id,
                line: payload.line,
                occurredAt: payload.occurred_at,
            };

            if (following) {
                setEntries((prev) => [...prev, entry]);
            } else {
                bufferRef.current = [...bufferRef.current, entry];
                setBufferedCount(bufferRef.current.length);
            }
        },
        [following],
    );

    useEffect(() => {
        if (following) {
            feedEndRef.current?.scrollIntoView({ block: 'end' });
        }
    }, [entries, following]);

    function resumeFollowing() {
        setEntries((prev) => [...prev, ...bufferRef.current]);
        bufferRef.current = [];
        setBufferedCount(0);
        setFollowing(true);
    }

    function pauseFollowing() {
        setFollowing(false);
    }

    function clearView() {
        setEntries([]);
    }

    const visibleLines = entries.filter((entry) =>
        filter.trim() === ''
            ? true
            : entry.line.toLowerCase().includes(filter.toLowerCase()),
    );

    function copyVisible() {
        void copy(visibleLines.map((entry) => entry.line).join('\n'));
    }

    function runPredefined(key: string, needsMessage: boolean) {
        if (needsMessage) {
            if (sayMessage.trim() === '') {
                return;
            }

            router.post(`/server/console/actions/${key}`, { message: sayMessage });
            setSayMessage('');

            return;
        }

        router.post(`/server/console/actions/${key}`);
    }

    return (
        <AppShell>
            <Head title="Console" />

            <header className="mb-[18px]">
                <h1
                    className="text-[20px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Console
                </h1>
                <p
                    className="mt-[2px] text-[12.5px]"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Live server output, predefined safe actions, and the
                    elevated-command approval flow.
                </p>
            </header>

            <div className="grid gap-[20px] lg:grid-cols-[1fr_320px] lg:items-start">
                <div className="flex min-w-0 flex-col gap-[14px]">
                    <ReconnectBanner />

                    {!rcon.available && (
                        <div
                            role="status"
                            data-test="console-rcon-banner"
                            className="rounded-[8px] border px-[12px] py-[9px] text-[12px] leading-[1.5]"
                            style={ckSubtleSurfaceStyle('danger')}
                        >
                            <strong className="font-bold">
                                RCON unavailable:
                            </strong>{' '}
                            {rcon.reason}
                        </div>
                    )}

                    <div
                        data-ck-console-feed
                        className="flex flex-col overflow-hidden rounded-[10px] border"
                        style={{ borderColor: 'var(--ck-border)' }}
                    >
                        <div
                            className="flex flex-wrap items-center gap-[8px] border-b px-[12px] py-[8px]"
                            style={{
                                borderColor: 'var(--ck-border)',
                                backgroundColor: 'var(--ck-surface)',
                            }}
                        >
                            <Input
                                aria-label="Filter console output"
                                placeholder="Filter…"
                                value={filter}
                                onChange={(event) => setFilter(event.target.value)}
                                className="h-[30px] max-w-[220px] font-mono text-[12px]"
                                data-test="console-filter"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={following ? pauseFollowing : resumeFollowing}
                                data-test="console-follow-toggle"
                            >
                                {following
                                    ? 'Pause'
                                    : `Resume${bufferedCount > 0 ? ` (${bufferedCount} new)` : ''}`}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={copyVisible}
                                data-test="console-copy"
                            >
                                Copy
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={clearView}
                                data-test="console-clear"
                            >
                                Clear view
                            </Button>
                        </div>

                        <div
                            role="log"
                            aria-label="Console output"
                            data-test="console-output"
                            className="max-h-[420px] min-h-[240px] overflow-auto px-[12px] py-[8px] font-mono text-[12px] leading-[1.6]"
                            style={{ backgroundColor: 'var(--ck-bg)' }}
                        >
                            {visibleLines.length === 0 ? (
                                <p style={{ color: 'var(--ck-text-2)' }}>
                                    No console output yet.
                                </p>
                            ) : (
                                visibleLines.map((entry) => (
                                    <div
                                        key={entry.id}
                                        className="whitespace-pre-wrap break-all"
                                        style={{ color: 'var(--ck-text)' }}
                                    >
                                        {entry.line}
                                    </div>
                                ))
                            )}
                            <div ref={feedEndRef} />
                        </div>
                    </div>

                    {!logs.available && (
                        <PageState
                            state="offline-server"
                            title="Console feed unavailable"
                            description={logs.reason ?? undefined}
                        />
                    )}

                    <div>
                        <h2
                            className="mb-[8px] text-[13px] font-bold"
                            style={{ color: 'var(--ck-text)' }}
                        >
                            Predefined safe actions
                        </h2>
                        <div className="flex flex-wrap gap-[8px]">
                            {predefinedActions.map((action) => (
                                <div
                                    key={action.key}
                                    className="flex items-center gap-[6px]"
                                >
                                    {action.needsMessage && (
                                        <Input
                                            aria-label="Broadcast message"
                                            placeholder="Message…"
                                            value={sayMessage}
                                            onChange={(event) =>
                                                setSayMessage(event.target.value)
                                            }
                                            className="h-[32px] w-[160px] text-[12px]"
                                            data-test="say-message-input"
                                        />
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            runPredefined(action.key, action.needsMessage)
                                        }
                                        title={action.consequence}
                                        data-test={`predefined-action-${action.key}`}
                                    >
                                        {action.label}
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div>
                        <h2
                            className="mb-[8px] text-[13px] font-bold"
                            style={{ color: 'var(--ck-text)' }}
                        >
                            Command history
                        </h2>
                        {commandHistory.length === 0 ? (
                            <p
                                className="text-[12px]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                No commands have been run yet.
                            </p>
                        ) : (
                            <ul className="grid gap-[6px]">
                                {commandHistory.map((operation) => (
                                    <li
                                        key={operation.id}
                                        className="flex flex-wrap items-center justify-between gap-[8px] rounded-[7px] border px-[10px] py-[6px] text-[12px]"
                                        style={{
                                            borderColor: 'var(--ck-border)',
                                        }}
                                    >
                                        <span
                                            className="font-mono"
                                            style={{ color: 'var(--ck-text)' }}
                                        >
                                            {operation.target}
                                        </span>
                                        <span
                                            style={{ color: 'var(--ck-text-2)' }}
                                        >
                                            {operation.status}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                <div
                    className={
                        isMobile ? undefined : 'lg:sticky lg:top-[20px]'
                    }
                >
                    <CommandComposer
                        rcon={rcon}
                        pendingOperation={pendingOperation}
                        composePreview={composePreview}
                        variant={isMobile ? 'sheet' : 'panel'}
                    />
                </div>
            </div>
        </AppShell>
    );
}
