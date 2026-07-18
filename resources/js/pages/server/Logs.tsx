import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useClipboard } from '@/hooks/use-clipboard';
import { AppShell } from '@/layouts/AppShell';
import type { ServerLogsProps } from '@/types/server';

const LEVEL_COLOR: Record<string, string> = {
    ERROR: 'var(--ck-danger)',
    WARN: 'var(--ck-warning)',
    INFO: 'var(--ck-text-2)',
    // --ck-text-2, not --ck-text-3: this is a real, readable level label
    // next to every log line, not decorative — --ck-text-3 is not
    // reliably AA-safe as body text outside --ck-bg (see
    // docs/architecture/decisions.md, Task 3 and Task 9).
    UNKNOWN: 'var(--ck-text-2)',
};

export default function ServerLogs({
    logs,
    filters,
    levels,
    entries,
    truncated,
    totalMatched,
}: ServerLogsProps) {
    const [level, setLevel] = useState(filters.level ?? 'all');
    const [player, setPlayer] = useState(filters.player ?? '');
    const [q, setQ] = useState(filters.q ?? '');
    const [, copy] = useClipboard();

    function applyFilters(event?: React.FormEvent) {
        event?.preventDefault();

        const params = new URLSearchParams();
        if (level !== 'all') params.set('level', level);
        if (player.trim() !== '') params.set('player', player.trim());
        if (q.trim() !== '') params.set('q', q.trim());

        router.get(`/server/logs?${params.toString()}`, {}, { preserveState: true });
    }

    function downloadUrl(): string {
        const params = new URLSearchParams();
        if (level !== 'all') params.set('level', level);
        if (player.trim() !== '') params.set('player', player.trim());
        if (q.trim() !== '') params.set('q', q.trim());

        return `/server/logs/download?${params.toString()}`;
    }

    function copyAll() {
        void copy(entries.map((entry) => entry.line).join('\n'));
    }

    return (
        <AppShell>
            <Head title="Logs" />

            <header className="mb-[18px]">
                <h1
                    className="text-[20px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Logs
                </h1>
                <p
                    className="mt-[2px] text-[12.5px]"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    File-based — stays usable even when RCON is
                    unavailable.
                </p>
            </header>

            {!logs.available && (
                <PageState
                    state="offline-server"
                    title="Logs unavailable"
                    description={logs.reason ?? undefined}
                    className="mb-[16px]"
                />
            )}

            <form
                onSubmit={applyFilters}
                className="mb-[14px] flex flex-wrap items-end gap-[10px]"
                aria-label="Log filters"
            >
                <div className="grid gap-[4px]">
                    <label
                        htmlFor="log-level"
                        className="text-[11px] font-semibold"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Level
                    </label>
                    <Select value={level} onValueChange={setLevel}>
                        <SelectTrigger id="log-level" className="w-[140px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All levels</SelectItem>
                            {levels.map((lvl) => (
                                <SelectItem key={lvl} value={lvl}>
                                    {lvl}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid gap-[4px]">
                    <label
                        htmlFor="log-player"
                        className="text-[11px] font-semibold"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Player
                    </label>
                    <Input
                        id="log-player"
                        value={player}
                        onChange={(event) => setPlayer(event.target.value)}
                        placeholder="Username"
                        className="w-[140px]"
                    />
                </div>

                <div className="grid gap-[4px]">
                    <label
                        htmlFor="log-q"
                        className="text-[11px] font-semibold"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Text
                    </label>
                    <Input
                        id="log-q"
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                        placeholder="Search…"
                        className="w-[180px]"
                        data-test="log-search"
                    />
                </div>

                <Button type="submit" size="sm" data-test="log-apply-filters">
                    Apply
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={copyAll}>
                    Copy
                </Button>
                <Button type="button" variant="outline" size="sm" asChild>
                    <a href={downloadUrl()} data-test="log-download">
                        Download
                    </a>
                </Button>
            </form>

            {truncated && (
                <p
                    className="mb-[10px] text-[11.5px]"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Showing the most recent {entries.length} of{' '}
                    {totalMatched} matches.
                </p>
            )}

            <div
                role="log"
                aria-label="Log entries"
                data-test="log-entries"
                className="max-h-[520px] overflow-auto rounded-[10px] border px-[12px] py-[8px] font-mono text-[12px] leading-[1.6]"
                style={{
                    backgroundColor: 'var(--ck-bg)',
                    borderColor: 'var(--ck-border)',
                }}
            >
                {entries.length === 0 ? (
                    <p style={{ color: 'var(--ck-text-2)' }}>
                        No matching log lines.
                    </p>
                ) : (
                    entries.map((entry) => (
                        <div
                            key={entry.id}
                            className="flex gap-[8px]"
                            style={
                                entry.matched
                                    ? undefined
                                    : { opacity: 0.55 }
                            }
                        >
                            <span
                                className="w-[46px] flex-none font-bold"
                                style={{ color: LEVEL_COLOR[entry.level] }}
                            >
                                {entry.level}
                            </span>
                            <span
                                className="whitespace-pre-wrap break-all"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                {entry.line}
                            </span>
                        </div>
                    ))
                )}
            </div>
        </AppShell>
    );
}
