import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import type { StatusBadgeStatus } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/AppShell';
import type { ActivityItemDTO, ActivityProps } from '@/types/activity';

const SOURCE_LABEL: Record<string, string> = {
    config: 'Configuration',
    plugin: 'Plugin',
    command: 'Command',
    'server-restart': 'Server restart',
    player: 'Player',
    'ai-proposal': 'AI proposal',
    'api-call': 'API call',
    'mcp-call': 'MCP call',
};

const STATUS_BADGE: Record<string, StatusBadgeStatus> = {
    succeeded: 'completed',
    completed: 'completed',
    failed: 'failed',
    rejected: 'failed',
    rolled_back: 'rolled-back',
    proposed: 'scheduled',
    approved: 'in-progress',
    running: 'in-progress',
    join: 'online',
    leave: 'offline',
    kick: 'failed',
    chat: 'unknown',
};

function ActivityRow({ item }: { item: ActivityItemDTO }) {
    return (
        <li
            className="flex flex-col gap-[6px] rounded-[10px] border p-[13px]"
            style={{
                backgroundColor: 'var(--ck-surface)',
                borderColor: 'var(--ck-border)',
            }}
            data-test="activity-item"
        >
            <div className="flex flex-wrap items-center justify-between gap-[8px]">
                <span
                    className="text-[11px] font-bold tracking-wide uppercase"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    {SOURCE_LABEL[item.source] ?? item.source}
                </span>
                <StatusText
                    status={STATUS_BADGE[item.status] ?? 'unknown'}
                    label={item.status}
                />
            </div>
            <p
                className="text-[13px] leading-[1.5]"
                style={{ color: 'var(--ck-text)' }}
            >
                {item.summary}
            </p>
            <div
                className="flex flex-wrap items-center gap-[10px] text-[11px]"
                style={{ color: 'var(--ck-text-2)' }}
            >
                <span>
                    {item.actor.type}
                    {item.actor.id ? ` · ${item.actor.id}` : ''}
                </span>
                <span>{item.timestamp ?? 'unknown time'}</span>
                {item.correlationId && (
                    <span
                        className="font-mono"
                        title="Correlation id"
                        data-test="activity-correlation-id"
                    >
                        {item.correlationId.slice(0, 8)}
                    </span>
                )}
            </div>
        </li>
    );
}

export default function Activity({ filters, sources, items }: ActivityProps) {
    const [source, setSource] = useState(filters.source ?? 'all');
    const [status, setStatus] = useState(filters.status ?? '');
    const [q, setQ] = useState(filters.q ?? '');

    function applyFilters(event?: React.FormEvent) {
        event?.preventDefault();

        const params = new URLSearchParams();
        if (source !== 'all') params.set('source', source);
        if (status.trim() !== '') params.set('status', status.trim());
        if (q.trim() !== '') params.set('q', q.trim());

        router.get(`/activity?${params.toString()}`, {}, { preserveState: true });
    }

    return (
        <AppShell>
            <Head title="Activity" />

            <header className="mb-[18px]">
                <h1
                    className="text-[20px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Activity
                </h1>
                <p
                    className="mt-[2px] text-[12.5px]"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    A chronological record of operations, configuration and
                    plugin changes, commands, server restarts, player
                    events, AI proposals, and API/MCP calls.
                </p>
            </header>

            <form
                onSubmit={applyFilters}
                className="mb-[16px] flex flex-wrap items-end gap-[10px]"
                aria-label="Activity filters"
            >
                <div className="grid gap-[4px]">
                    <label
                        htmlFor="activity-source"
                        className="text-[11px] font-semibold"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Source
                    </label>
                    <Select value={source} onValueChange={setSource}>
                        <SelectTrigger
                            id="activity-source"
                            className="w-[180px]"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All sources</SelectItem>
                            {sources.map((s) => (
                                <SelectItem key={s} value={s}>
                                    {SOURCE_LABEL[s] ?? s}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid gap-[4px]">
                    <label
                        htmlFor="activity-status"
                        className="text-[11px] font-semibold"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Status
                    </label>
                    <Input
                        id="activity-status"
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        placeholder="e.g. failed"
                        className="w-[140px]"
                    />
                </div>

                <div className="grid gap-[4px]">
                    <label
                        htmlFor="activity-q"
                        className="text-[11px] font-semibold"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Text
                    </label>
                    <Input
                        id="activity-q"
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                        placeholder="Search…"
                        className="w-[200px]"
                        data-test="activity-search"
                    />
                </div>

                <Button type="submit" size="sm" data-test="activity-apply-filters">
                    Apply
                </Button>
            </form>

            {items.length === 0 ? (
                <PageState
                    state="empty"
                    title="No activity yet"
                    description="Nothing matches these filters, or nothing has happened yet."
                />
            ) : (
                <ul className="grid gap-[10px]" data-test="activity-list">
                    {items.map((item) => (
                        <ActivityRow key={item.id} item={item} />
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
