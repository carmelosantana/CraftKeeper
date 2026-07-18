import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { Button } from '@/components/ui/button';
import { CompatibilityEvidence } from '@/features/plugins/CompatibilityEvidence';
import { OperationProgress } from '@/features/operations/OperationProgress';
import { AppShell } from '@/layouts/AppShell';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import type { PluginOperationProps } from '@/types/plugins';

/**
 * The install plan review + operation progress page — reached after
 * ANY plugin.* proposal (install/update/disable/remove/rollback). Shows
 * the full plan (artifact identity, source, checksum, compatibility
 * evidence, dependencies, conflicts, file changes, restart requirement)
 * BEFORE approve/reject — per the plan's "consequences before the
 * confirmation control" — then App\Features\Operations\OperationProgress
 * for live status. `restartRequired`/rollback controls stay visible on
 * BOTH desktop and mobile: nothing here is hidden behind a
 * desktop-only affordance, and the restart banner stays up until a
 * server start is actually OBSERVED (never cleared just because the
 * write succeeded — see App\Plugins\PluginLifecycleService::
 * isRestartObserved()).
 *
 * "Undo this change" is itself high-risk (App\Operations\Handlers\
 * PluginOperationHandler::undoFromPreservedArtifact() replaces whatever
 * is CURRENTLY installed), so — same principle as Show.tsx's disable/
 * remove/restore controls — it shows its consequence text BEFORE a
 * separate confirm control, rather than posting on a single click.
 */
export default function PluginOperation({ operation, plan, targetRelativePath, canRollback, restartObserved }: PluginOperationProps) {
    const [pending, setPending] = useState(false);
    const [confirmingRollback, setConfirmingRollback] = useState(false);

    function post(action: 'approve' | 'reject' | 'rollback') {
        router.post(
            `/plugins/operations/${operation.id}/${action}`,
            {},
            { onStart: () => setPending(true), onFinish: () => { setPending(false); setConfirmingRollback(false); } },
        );
    }

    const isProposed = operation.status === 'proposed';
    const isTerminalSuccessOrFailure = operation.status === 'succeeded' || operation.status === 'failed';
    const showRestartBanner = plan?.restartRequired && isTerminalSuccessOrFailure && !restartObserved;

    return (
        <AppShell>
            <Head title={`Plugin ${operation.type}`} />

            <nav aria-label="Breadcrumb" className="mb-[6px] text-[11.5px]" style={{ color: 'var(--ck-text-2)' }}>
                <Link href="/plugins" className="underline" style={{ color: 'var(--ck-accent)' }}>
                    Plugins
                </Link>{' '}
                {targetRelativePath && (
                    <>
                        /{' '}
                        <Link
                            href={`/plugins/${targetRelativePath.split('/').pop()}`}
                            className="underline"
                            style={{ color: 'var(--ck-accent)' }}
                        >
                            {targetRelativePath}
                        </Link>
                    </>
                )}
            </nav>

            <header className="mb-[18px]">
                <h1 className="text-[20px] font-bold" style={{ color: 'var(--ck-text)' }}>
                    {operation.type}
                </h1>
                <OperationProgress operation={operation} className="mt-[6px]" />
            </header>

            {showRestartBanner && (
                <RestartRequired
                    variant="banner"
                    className="mb-[16px]"
                    message={
                        <>
                            <strong className="font-bold">Restart required.</strong> This change will not take
                            effect until the Minecraft server restarts. This notice stays until a server
                            start is observed.
                        </>
                    }
                />
            )}

            {plan && (
                <section
                    data-ck-plan-review
                    className="grid gap-[16px] rounded-[12px] border p-[18px]"
                    style={{ backgroundColor: 'var(--ck-elevated)', borderColor: 'var(--ck-border)' }}
                >
                    <dl className="grid grid-cols-[130px_1fr] gap-y-[6px] text-[12.5px]">
                        <dt style={{ color: 'var(--ck-text-2)' }}>Artifact</dt>
                        <dd style={{ color: 'var(--ck-text)' }}>
                            {plan.artifact.name ?? '(unknown)'} {plan.artifact.version ? `v${plan.artifact.version}` : ''}
                        </dd>
                        {plan.source && (
                            <>
                                <dt style={{ color: 'var(--ck-text-2)' }}>Source</dt>
                                <dd style={{ color: 'var(--ck-text)' }}>{plan.source}</dd>
                            </>
                        )}
                        {plan.checksum && (
                            <>
                                <dt style={{ color: 'var(--ck-text-2)' }}>Checksum</dt>
                                <dd className="break-all font-mono text-[11px]" style={{ color: 'var(--ck-text)' }} data-test="plan-checksum">
                                    {plan.checksum}
                                </dd>
                            </>
                        )}
                    </dl>

                    {plan.compatibility && (
                        <div>
                            <h3 className="mb-[8px] text-[11px] font-bold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                                Compatibility
                            </h3>
                            <CompatibilityEvidence state={plan.compatibility.state} evidence={plan.compatibility.evidence} />
                        </div>
                    )}

                    {plan.unmetHardDependencies && plan.unmetHardDependencies.length > 0 && (
                        <div
                            role="alert"
                            className="rounded-[8px] border px-[12px] py-[9px] text-[12px]"
                            style={ckSubtleSurfaceStyle('danger')}
                            data-test="unmet-dependencies"
                        >
                            <strong className="font-bold">Missing dependencies:</strong>{' '}
                            {plan.unmetHardDependencies.join(', ')}
                        </div>
                    )}

                    <div>
                        <h3 className="mb-[6px] text-[11px] font-bold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                            File changes
                        </h3>
                        <ul className="grid gap-[3px] font-mono text-[12px]" style={{ color: 'var(--ck-text)' }}>
                            {plan.fileChanges.map((change, i) => (
                                <li key={i}>{change}</li>
                            ))}
                        </ul>
                    </div>

                    <RestartRequired
                        variant="chip"
                        label={plan.restartRequired ? 'Restart required' : 'No restart needed'}
                    />

                    {isProposed && (
                        <div className="flex flex-wrap items-center gap-[10px] pt-[4px]">
                            <Button
                                type="button"
                                onClick={() => post('approve')}
                                disabled={pending}
                                data-test="approve-plugin-operation"
                            >
                                {pending ? 'Applying…' : 'Approve & apply'}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => post('reject')}
                                disabled={pending}
                                data-test="reject-plugin-operation"
                            >
                                Discard
                            </Button>
                        </div>
                    )}
                </section>
            )}

            {canRollback && (
                <div className="mt-[16px]" data-test="rollback-controls">
                    {confirmingRollback ? (
                        <div
                            className="flex flex-wrap items-center gap-[10px] rounded-[8px] border px-[12px] py-[10px]"
                            style={ckSubtleSurfaceStyle('warning')}
                            data-test="rollback-confirm-panel"
                        >
                            <span className="text-[12px]" style={{ color: 'var(--ck-text)' }}>
                                Replaces {targetRelativePath ?? 'the currently installed file'} with the artifact
                                this {operation.type} replaced; the current version will be preserved for rollback.
                            </span>
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                disabled={pending}
                                onClick={() => post('rollback')}
                                data-test="confirm-rollback-this-operation"
                            >
                                {pending ? 'Rolling back…' : 'Confirm undo'}
                            </Button>
                            <Button type="button" variant="outline" size="sm" onClick={() => setConfirmingRollback(false)}>
                                Cancel
                            </Button>
                        </div>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirmingRollback(true)}
                            disabled={pending}
                            data-test="rollback-this-operation"
                        >
                            Undo this change
                        </Button>
                    )}
                </div>
            )}

            {operation.outcome && (
                <p
                    className="mt-[16px] text-[12.5px]"
                    style={{ color: operation.errorCode ? 'var(--ck-danger)' : 'var(--ck-text-2)' }}
                    data-test="operation-outcome"
                >
                    {operation.outcome}
                </p>
            )}
        </AppShell>
    );
}
