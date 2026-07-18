import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/AppShell';
import type { ConfigHistoryProps } from '@/types/config';

/**
 * Revision history + restore, per the plan's "Configuration conflict
 * resolution, save result, history, revision detail, and restore" (not
 * individually mocked — composed from the kit's ApprovalPanel/DataTable
 * patterns, per Design/handoff/pages.json's `notMockedYet`).
 *
 * Restore copy deliberately says "restore the recorded values," never
 * "restore the exact file" or "byte-for-byte" — App\Config\
 * ConfigRevisionService::restore() is an explicitly best-effort,
 * scalar-leaf field diff (see its docblock), not a guaranteed exact
 * restoration, and the UI must not promise more than the service delivers.
 */
export default function ConfigHistory({ path, revisions }: ConfigHistoryProps) {
    const [restoringId, setRestoringId] = useState<number | null>(null);

    function restore(revisionId: number) {
        router.post(
            `/configurations/revisions/${revisionId}/restore`,
            {},
            {
                onStart: () => setRestoringId(revisionId),
                onFinish: () => setRestoringId(null),
            },
        );
    }

    return (
        <AppShell>
            <Head title={`History — ${path}`} />

            <nav
                aria-label="Breadcrumb"
                className="mb-[6px] text-[11.5px]"
                style={{ color: 'var(--ck-text-2)' }}
            >
                <Link
                    href="/configurations"
                    className="underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    Configurations
                </Link>{' '}
                /{' '}
                <Link
                    href={`/configurations/${path}`}
                    className="underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    {path}
                </Link>{' '}
                / History
            </nav>

            <h1
                className="mb-[6px] text-[20px] font-bold"
                style={{ color: 'var(--ck-text)' }}
            >
                Revision history
            </h1>
            <p
                className="mb-[18px] text-[12.5px] leading-[1.5]"
                style={{ color: 'var(--ck-text-2)' }}
            >
                Restoring proposes the recorded field values from a past
                revision as a new, reviewable change — it does not guarantee
                an exact, byte-for-byte copy of the original file (comments
                and formatting outside recognized fields are not restored).
            </p>

            {revisions.length === 0 ? (
                <PageState
                    state="empty"
                    title="No revisions yet"
                    description="This file has not had a CraftKeeper-applied change yet."
                />
            ) : (
                <ul className="grid gap-[10px]">
                    {revisions.map((revision) => (
                        <li
                            key={revision.id}
                            className="grid gap-[8px] rounded-[10px] border px-[14px] py-[12px]"
                            style={{
                                borderColor: 'var(--ck-border)',
                                backgroundColor: 'var(--ck-surface)',
                            }}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-[8px]">
                                <div>
                                    <span
                                        className="text-[12.5px] font-semibold"
                                        style={{ color: 'var(--ck-text)' }}
                                    >
                                        {revision.summary ?? `${revision.kind} revision`}
                                    </span>
                                    <div
                                        className="font-mono text-[11px]"
                                        style={{ color: 'var(--ck-text-2)' }}
                                    >
                                        {revision.createdAt} · {revision.kind}
                                        {revision.authorType
                                            ? ` · ${revision.authorType}`
                                            : ''}
                                    </div>
                                </div>
                                <div className="flex items-center gap-[8px]">
                                    {revision.restartImpact &&
                                        revision.restartImpact !== 'none' && (
                                            <RestartRequired
                                                variant="chip"
                                                label={
                                                    revision.restartImpact ===
                                                    'restart'
                                                        ? 'Restart required'
                                                        : 'Reload required'
                                                }
                                            />
                                        )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => restore(revision.id)}
                                        disabled={restoringId === revision.id}
                                        data-test={`restore-revision-${revision.id}`}
                                    >
                                        {restoringId === revision.id
                                            ? 'Preparing…'
                                            : 'Restore this revision'}
                                    </Button>
                                </div>
                            </div>
                            {revision.diff && (
                                <details>
                                    <summary
                                        className="cursor-pointer text-[11.5px] font-semibold"
                                        style={{ color: 'var(--ck-accent)' }}
                                    >
                                        View diff
                                    </summary>
                                    <pre
                                        className="mt-[6px] max-h-[220px] overflow-auto rounded-[8px] border px-[10px] py-[8px] font-mono text-[11px] leading-[1.5] whitespace-pre-wrap"
                                        style={{
                                            borderColor: 'var(--ck-border)',
                                            backgroundColor: 'var(--ck-bg)',
                                            color: 'var(--ck-text-2)',
                                        }}
                                    >
                                        {revision.diff}
                                    </pre>
                                </details>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
