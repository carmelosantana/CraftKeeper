import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { AppShell } from '@/layouts/AppShell';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import type { ConfigConflictProps } from '@/types/config';

/**
 * Conflict resolution — the file changed outside CraftKeeper between when
 * the operator started editing and when they saved, so
 * App\Config\Exceptions\ConfigConflict (Task 8) refused to propose a
 * change that would silently overwrite it. Per ambiguity resolution #3:
 * shows base (what the operator was editing from) / disk (the file's real
 * current content) / proposed (what the operator was about to change),
 * with three actions — reload disk, copy a proposed value, or build a
 * fresh proposal from manually selected per-field values. Every value
 * shown here already arrived pre-redacted from the server.
 */
export default function ConfigConflict({
    path,
    expectedSha256,
    actualSha256,
    base,
    disk,
    diskSha256,
    proposed,
}: ConfigConflictProps) {
    const [selection, setSelection] = useState<Record<string, 'disk' | 'proposed'>>(
        () => Object.fromEntries(proposed.map((row) => [row.path, 'proposed'])),
    );
    const [, copy] = useClipboard();
    const [submitting, setSubmitting] = useState(false);

    function createFreshProposal() {
        const changes = proposed.map((row) => ({
            path: row.path,
            value: selection[row.path] === 'disk' ? row.before : row.after,
        }));

        router.post(
            `/configurations/${path}`,
            { mode: 'fields', base_sha256: diskSha256, changes },
            {
                preserveScroll: true,
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <AppShell>
            <Head title="Configuration conflict" />

            <div
                role="alert"
                className="mb-[18px] rounded-[10px] border px-[16px] py-[13px]"
                style={ckSubtleSurfaceStyle('warning')}
            >
                <h1
                    className="text-[16px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    {path} changed outside CraftKeeper
                </h1>
                <p
                    className="mt-[4px] text-[12.5px] leading-[1.5]"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Someone or something edited this file after you started —
                    CraftKeeper refused to overwrite it. Review the three
                    versions below and choose how to proceed.
                </p>
                <p
                    className="mt-[6px] font-mono text-[10.5px]"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    expected {expectedSha256.slice(0, 12)}… · actual{' '}
                    {actualSha256.slice(0, 12)}…
                </p>
            </div>

            <div className="mb-[20px] grid gap-[14px] lg:grid-cols-3">
                <TextPanel title="Your base" content={base} />
                <TextPanel title="Currently on disk" content={disk} />
                <div className="grid gap-[8px]">
                    <h2
                        className="text-[11px] font-bold tracking-wide uppercase"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Your proposed changes
                    </h2>
                    <div className="grid gap-[8px]">
                        {proposed.map((row) => (
                            <div
                                key={row.path}
                                className="rounded-[8px] border px-[11px] py-[9px] text-[12px]"
                                style={{
                                    borderColor: 'var(--ck-border)',
                                    backgroundColor: 'var(--ck-surface)',
                                }}
                            >
                                <div
                                    className="flex items-center justify-between gap-[8px] font-mono text-[11.5px] font-semibold"
                                    style={{ color: 'var(--ck-text)' }}
                                >
                                    {row.path}
                                    <button
                                        type="button"
                                        className="text-[10.5px] font-semibold underline"
                                        style={{ color: 'var(--ck-accent)' }}
                                        onClick={() => copy(row.after)}
                                        data-test={`conflict-copy-${row.path}`}
                                    >
                                        Copy proposed value
                                    </button>
                                </div>
                                <fieldset className="mt-[6px] grid gap-[4px]">
                                    <legend className="sr-only">
                                        Resolve {row.path}
                                    </legend>
                                    <label className="flex items-center gap-[6px] text-[11.5px]">
                                        <input
                                            type="radio"
                                            name={`resolve-${row.path}`}
                                            checked={selection[row.path] === 'disk'}
                                            onChange={() =>
                                                setSelection((prev) => ({
                                                    ...prev,
                                                    [row.path]: 'disk',
                                                }))
                                            }
                                        />
                                        Keep disk value: {row.before}
                                    </label>
                                    <label className="flex items-center gap-[6px] text-[11.5px]">
                                        <input
                                            type="radio"
                                            name={`resolve-${row.path}`}
                                            checked={
                                                selection[row.path] === 'proposed'
                                            }
                                            onChange={() =>
                                                setSelection((prev) => ({
                                                    ...prev,
                                                    [row.path]: 'proposed',
                                                }))
                                            }
                                        />
                                        Use my proposed value: {row.after}
                                    </label>
                                </fieldset>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-[10px]">
                <Link
                    href={`/configurations/${path}`}
                    className="inline-flex h-9 items-center rounded-md border px-4 text-[13px] font-semibold"
                    style={{ borderColor: 'var(--ck-border-strong)' }}
                    data-test="conflict-reload-disk"
                >
                    Reload disk
                </Link>
                <Button
                    type="button"
                    onClick={createFreshProposal}
                    disabled={submitting || proposed.length === 0}
                    data-test="conflict-create-proposal"
                >
                    {submitting
                        ? 'Creating…'
                        : 'Create a fresh proposal from my selections'}
                </Button>
            </div>
        </AppShell>
    );
}

function TextPanel({ title, content }: { title: string; content: string }) {
    return (
        <div className="grid gap-[8px]">
            <h2
                className="text-[11px] font-bold tracking-wide uppercase"
                style={{ color: 'var(--ck-text-2)' }}
            >
                {title}
            </h2>
            <pre
                className="max-h-[320px] overflow-auto rounded-[8px] border px-[10px] py-[8px] font-mono text-[11px] leading-[1.5] whitespace-pre-wrap"
                style={{
                    borderColor: 'var(--ck-border)',
                    backgroundColor: 'var(--ck-bg)',
                    color: 'var(--ck-text-2)',
                }}
            >
                {content}
            </pre>
        </div>
    );
}
