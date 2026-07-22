import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AppShell } from '@/layouts/AppShell';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import type { ServerIndexProps } from '@/types/server';

export default function ServerIndex({
    rcon,
    logs,
    version,
    paths,
    predefinedActions,
}: ServerIndexProps) {
    const [sayMessage, setSayMessage] = useState('');

    function runPredefined(key: string, needsMessage: boolean) {
        if (needsMessage) {
            if (sayMessage.trim() === '') {
                return;
            }

            router.post(`/server/console/actions/${key}`, {
                message: sayMessage,
            });
            setSayMessage('');

            return;
        }

        router.post(`/server/console/actions/${key}`);
    }

    return (
        <AppShell>
            <Head title="Server" />

            <header className="mb-[18px] flex flex-wrap items-center justify-between gap-[10px]">
                <h1
                    className="text-[20px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Server
                </h1>
                <Link
                    href="/server/console"
                    className="text-[12.5px] font-semibold underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    Open Console
                </Link>
            </header>

            <div className="grid gap-[16px] md:grid-cols-2">
                <section
                    className="rounded-[12px] border p-[16px]"
                    style={{
                        backgroundColor: 'var(--ck-surface)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <h2
                        className="mb-[10px] text-[12px] font-bold tracking-wide uppercase"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Connection
                    </h2>
                    <div className="grid gap-[8px]">
                        <div className="flex items-center justify-between">
                            <span style={{ color: 'var(--ck-text)' }}>
                                RCON
                            </span>
                            <StatusText
                                status={rcon.available ? 'online' : 'offline'}
                                label={
                                    rcon.available
                                        ? 'Connected'
                                        : 'Unavailable'
                                }
                            />
                        </div>
                        {!rcon.available && rcon.reason && (
                            <p
                                className="text-[12px]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                {rcon.reason}
                            </p>
                        )}
                        <div className="flex items-center justify-between">
                            <span style={{ color: 'var(--ck-text)' }}>
                                Server logs
                            </span>
                            <StatusText
                                status={logs.available ? 'online' : 'offline'}
                                label={
                                    logs.available
                                        ? 'Available'
                                        : 'Unavailable'
                                }
                            />
                        </div>
                    </div>
                </section>

                <section
                    className="rounded-[12px] border p-[16px]"
                    style={{
                        backgroundColor: 'var(--ck-surface)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <h2
                        className="mb-[10px] text-[12px] font-bold tracking-wide uppercase"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Version
                    </h2>
                    {version.known ? (
                        <div data-test="server-version">
                            <p
                                className="text-[16px] font-bold"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                {version.label}
                            </p>
                            <p
                                className="text-[11.5px]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                Discovered from{' '}
                                {version.source === 'jar'
                                    ? 'a server JAR filename'
                                    : version.source === 'log'
                                      ? 'the startup log'
                                      : "the server's own version_history.json"}
                            </p>
                        </div>
                    ) : (
                        <PageState
                            state="empty"
                            title="Unavailable"
                            description={version.reason ?? 'The server version could not be determined.'}
                        />
                    )}
                </section>

                <section
                    className="rounded-[12px] border p-[16px] md:col-span-2"
                    style={{
                        backgroundColor: 'var(--ck-surface)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <h2
                        className="mb-[10px] text-[12px] font-bold tracking-wide uppercase"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Paths
                    </h2>
                    <dl className="grid gap-[6px] text-[12.5px]">
                        <div className="flex flex-wrap justify-between gap-[8px]">
                            <dt style={{ color: 'var(--ck-text-2)' }}>
                                Minecraft root
                            </dt>
                            <dd
                                className="font-mono break-all"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                {paths.minecraftRoot}
                            </dd>
                        </div>
                        <div className="flex flex-wrap justify-between gap-[8px]">
                            <dt style={{ color: 'var(--ck-text-2)' }}>
                                Log file
                            </dt>
                            <dd
                                className="font-mono"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                {paths.logFile}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    className="rounded-[12px] border p-[16px] md:col-span-2"
                    style={{
                        backgroundColor: 'var(--ck-surface)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <h2
                        className="mb-[10px] text-[12px] font-bold tracking-wide uppercase"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Predefined safe actions
                    </h2>
                    {!rcon.available && (
                        <div
                            role="status"
                            className="mb-[10px] rounded-[8px] border px-[12px] py-[9px] text-[12px]"
                            style={ckSubtleSurfaceStyle('warning')}
                        >
                            RCON is unavailable — actions below will likely
                            fail until it recovers.
                        </div>
                    )}
                    <div className="flex flex-wrap gap-[10px]">
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
                                    />
                                )}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        runPredefined(
                                            action.key,
                                            action.needsMessage,
                                        )
                                    }
                                    title={action.consequence}
                                    data-test={`predefined-action-${action.key}`}
                                >
                                    {action.label}
                                </Button>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </AppShell>
    );
}
