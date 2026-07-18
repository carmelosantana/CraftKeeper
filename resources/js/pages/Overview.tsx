import { Head, Link } from '@inertiajs/react';
import { PageState } from '@/components/craftkeeper/PageState';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import type { StatusBadgeStatus } from '@/components/craftkeeper/StatusBadge';
import { AppShell } from '@/layouts/AppShell';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import type { OverviewProps } from '@/types/server';

function Card({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section
            className="flex flex-col gap-[10px] rounded-[12px] border p-[16px]"
            style={{
                backgroundColor: 'var(--ck-surface)',
                borderColor: 'var(--ck-border)',
            }}
        >
            <h2
                className="text-[12px] font-bold tracking-wide uppercase"
                style={{ color: 'var(--ck-text-2)' }}
            >
                {title}
            </h2>
            {children}
        </section>
    );
}

export default function Overview({
    health,
    resources,
    pendingRestart,
    recentOperations,
    recentPlayerActivity,
    attentionItems,
}: OverviewProps) {
    const rconStatus: StatusBadgeStatus = health.rcon.available
        ? 'online'
        : 'offline';

    return (
        <AppShell>
            <Head title="Overview" />

            <header className="mb-[18px]">
                <h1
                    className="text-[20px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Overview
                </h1>
            </header>

            {pendingRestart && (
                <RestartRequired variant="banner" className="mb-[16px]" />
            )}

            {attentionItems.length > 0 && (
                <section
                    aria-label="Needs attention"
                    className="mb-[18px] grid gap-[8px]"
                    data-test="attention-items"
                >
                    {attentionItems.map((item) => (
                        <div
                            key={item.kind}
                            role="status"
                            className="rounded-[8px] border px-[12px] py-[9px] text-[12.5px] leading-[1.5]"
                            style={ckSubtleSurfaceStyle('warning')}
                        >
                            {item.message}
                        </div>
                    ))}
                </section>
            )}

            <div className="grid gap-[16px] md:grid-cols-2">
                <Card title="Server health">
                    <div className="flex flex-col gap-[8px]">
                        <div className="flex items-center justify-between">
                            <span
                                className="text-[12.5px]"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                RCON
                            </span>
                            <StatusText
                                status={rconStatus}
                                label={
                                    health.rcon.available
                                        ? 'Online'
                                        : 'Unavailable'
                                }
                            />
                        </div>
                        {!health.rcon.available && (
                            <p
                                className="text-[12px]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                {health.rcon.reason}
                            </p>
                        )}
                        <div className="flex items-center justify-between">
                            <span
                                className="text-[12.5px]"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                Server logs
                            </span>
                            <StatusText
                                status={
                                    health.logs.available
                                        ? 'online'
                                        : 'offline'
                                }
                                label={
                                    health.logs.available
                                        ? 'Available'
                                        : 'Unavailable'
                                }
                            />
                        </div>
                    </div>
                </Card>

                <Card title="Online players">
                    {health.rcon.available &&
                    health.rcon.playerCount !== null &&
                    health.rcon.playerCount !== undefined ? (
                        <p
                            className="text-[22px] font-bold"
                            style={{ color: 'var(--ck-text)' }}
                            data-test="online-player-count"
                        >
                            {health.rcon.playerCount}
                        </p>
                    ) : (
                        <PageState
                            state="rcon-disconnected"
                            description={health.rcon.reason ?? undefined}
                        />
                    )}
                </Card>

                <Card title="Resource summary">
                    <PageState
                        state="empty"
                        title="Not reported"
                        description={
                            resources.reason ??
                            'Resource metrics are not collected in this version.'
                        }
                    />
                </Card>

                <Card title="Recent operations">
                    {recentOperations.length === 0 ? (
                        <p
                            className="text-[12.5px]"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            No operations recorded yet.
                        </p>
                    ) : (
                        <ul className="grid gap-[6px]">
                            {recentOperations.map((operation) => (
                                <li
                                    key={operation.id}
                                    className="flex items-center justify-between gap-[8px] text-[12.5px]"
                                >
                                    <span
                                        className="truncate font-mono"
                                        style={{ color: 'var(--ck-text)' }}
                                    >
                                        {operation.type}
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
                </Card>

                <Card title="Recent player activity">
                    {recentPlayerActivity.length === 0 ? (
                        <p
                            className="text-[12.5px]"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            No player activity recorded yet.
                        </p>
                    ) : (
                        <ul className="grid gap-[6px]">
                            {recentPlayerActivity.map((event) => (
                                <li
                                    key={event.id}
                                    className="text-[12.5px]"
                                    style={{ color: 'var(--ck-text)' }}
                                >
                                    {event.player} — {event.kind}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <div className="mt-[18px] flex flex-wrap gap-[10px]">
                <Link
                    href="/server/console"
                    className="text-[12.5px] font-semibold underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    Open Console
                </Link>
                <Link
                    href="/server/players"
                    className="text-[12.5px] font-semibold underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    View players
                </Link>
                <Link
                    href="/activity"
                    className="text-[12.5px] font-semibold underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    View activity
                </Link>
            </div>
        </AppShell>
    );
}
