import { Head, Link, router } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import { useState } from 'react';
import { ProvenanceBadge } from '@/components/craftkeeper/ProvenanceBadge';
import type { ProvenanceSource } from '@/components/craftkeeper/ProvenanceBadge';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { CompatibilityEvidence } from '@/features/plugins/CompatibilityEvidence';
import { AppShell } from '@/layouts/AppShell';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import type { PluginShowProps } from '@/types/plugins';

type GuardedAction = 'disable' | 'remove' | null;

/**
 * Installed plugin detail — disk state, source, version, compatibility
 * evidence, dependencies, checksum, pending action, history, and the
 * GUARDED lifecycle actions (update/disable/remove/rollback). Every
 * destructive AND high-risk action — disable/remove, and "Restore this
 * version" (which replaces the RUNNING artifact) — shows its consequence
 * text BEFORE the confirm control appears (per the plan's "Destructive
 * and high-risk actions show consequences before the confirmation
 * control" — same principle resources/js/features/config/DiffReview.tsx
 * already applies), and every control here is always in normal document
 * flow — reachable on mobile exactly as on desktop, never hidden behind
 * a hover-only or desktop-only affordance.
 */
export default function PluginShow({ plugin, history, rollbackArtifacts }: PluginShowProps) {
    const [confirming, setConfirming] = useState<GuardedAction>(null);
    const [confirmingRestoreId, setConfirmingRestoreId] = useState<number | null>(null);
    const [pending, setPending] = useState(false);

    function post(url: string, data: Record<string, FormDataConvertible> = {}) {
        router.post(url, data, {
            onStart: () => setPending(true),
            onFinish: () => {
                setPending(false);
                setConfirming(null);
                setConfirmingRestoreId(null);
            },
        });
    }

    const hasPending = plugin.pendingOperation !== null;

    return (
        <AppShell>
            <Head title={plugin.name ?? plugin.filename} />

            <nav aria-label="Breadcrumb" className="mb-[6px] text-[11.5px]" style={{ color: 'var(--ck-text-2)' }}>
                <Link href="/plugins" className="underline" style={{ color: 'var(--ck-accent)' }}>
                    Plugins
                </Link>{' '}
                / {plugin.filename}
            </nav>

            <header className="mb-[18px] flex flex-wrap items-start justify-between gap-[12px]">
                <div>
                    <h1 className="text-[20px] font-bold" style={{ color: 'var(--ck-text)' }}>
                        {plugin.name ?? plugin.filename}
                    </h1>
                    <div
                        className="mt-[4px] flex flex-wrap items-center gap-[10px] font-mono text-[12px]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        <span>{plugin.relativePath}</span>
                        <ProvenanceBadge source={plugin.provenance as ProvenanceSource} />
                        <StatusText status={plugin.enabled ? 'online' : 'offline'} label={plugin.enabled ? 'Enabled' : 'Disabled'} />
                    </div>
                </div>
                <Link
                    href={`/plugins/discover?q=${encodeURIComponent(plugin.name ?? '')}`}
                    className="text-[12.5px] font-semibold underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    Check catalog for updates
                </Link>
            </header>

            {plugin.missingSince && (
                <div
                    role="alert"
                    className="mb-[16px] rounded-[8px] border px-[12px] py-[10px] text-[12.5px]"
                    style={ckSubtleSurfaceStyle('danger')}
                >
                    This plugin's file is missing from disk (last seen {plugin.missingSince}). History and
                    provenance are preserved.
                </div>
            )}

            {hasPending && plugin.pendingOperation && (
                <div className="mb-[16px] flex flex-wrap items-center gap-[10px]" data-test="pending-operation">
                    <RestartRequired variant="chip" label={`Pending: ${plugin.pendingOperation.type} (${plugin.pendingOperation.status})`} />
                    <Link
                        href={`/plugins/operations/${plugin.pendingOperation.id}`}
                        className="text-[12.5px] font-semibold underline"
                        style={{ color: 'var(--ck-accent)' }}
                        data-test="review-pending-operation"
                    >
                        Review pending change
                    </Link>
                </div>
            )}

            <div className="grid gap-[16px] lg:grid-cols-2">
                <section
                    className="grid gap-[10px] rounded-[12px] border p-[16px]"
                    style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
                >
                    <h2 className="text-[12px] font-bold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                        Details
                    </h2>
                    <dl className="grid grid-cols-[130px_1fr] gap-y-[6px] text-[12.5px]">
                        <dt style={{ color: 'var(--ck-text-2)' }}>Version</dt>
                        <dd style={{ color: 'var(--ck-text)' }}>{plugin.version ?? '(unknown)'}</dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>API version</dt>
                        <dd style={{ color: 'var(--ck-text)' }}>{plugin.apiVersion ?? '(unknown)'}</dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>Main class</dt>
                        <dd className="break-all font-mono text-[11px]" style={{ color: 'var(--ck-text)' }}>
                            {plugin.mainClass ?? '(unknown)'}
                        </dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>Checksum</dt>
                        <dd className="break-all font-mono text-[11px]" style={{ color: 'var(--ck-text)' }} data-test="plugin-checksum">
                            {plugin.sha256 ?? '(unknown)'}
                        </dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>Size</dt>
                        <dd style={{ color: 'var(--ck-text)' }}>{plugin.sizeBytes ?? '?'} bytes</dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>Config</dt>
                        <dd>
                            <Link
                                href={`/configurations?q=${encodeURIComponent(plugin.name ?? '')}`}
                                className="underline"
                                style={{ color: 'var(--ck-accent)' }}
                            >
                                View config files
                            </Link>
                        </dd>
                    </dl>
                </section>

                <section
                    className="grid gap-[10px] rounded-[12px] border p-[16px]"
                    style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
                >
                    <h2 className="text-[12px] font-bold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                        Compatibility
                    </h2>
                    {plugin.compatibilityState ? (
                        <CompatibilityEvidence state={plugin.compatibilityState} evidence={plugin.compatibilityEvidence} />
                    ) : (
                        <p className="text-[12px]" style={{ color: 'var(--ck-text-2)' }}>
                            Not yet assessed.
                        </p>
                    )}
                    <h3 className="mt-[6px] text-[11px] font-bold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                        Dependencies
                    </h3>
                    <p className="text-[12px]" style={{ color: 'var(--ck-text)' }}>
                        {plugin.hardDependencies.length > 0 && <>Required: {plugin.hardDependencies.join(', ')}. </>}
                        {plugin.softDependencies.length > 0 && <>Optional: {plugin.softDependencies.join(', ')}.</>}
                        {plugin.hardDependencies.length === 0 && plugin.softDependencies.length === 0 && 'None declared.'}
                    </p>
                </section>
            </div>

            <section className="mt-[16px] rounded-[12px] border p-[16px]" style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}>
                <h2 className="mb-[12px] text-[12px] font-bold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                    Actions
                </h2>

                <div className="flex flex-wrap gap-[10px]" data-test="plugin-actions">
                    {plugin.enabled ? (
                        confirming === 'disable' ? (
                            <div
                                className="flex flex-wrap items-center gap-[10px] rounded-[8px] border px-[12px] py-[10px]"
                                style={ckSubtleSurfaceStyle('warning')}
                                data-test="disable-confirm-panel"
                            >
                                <span className="text-[12px]" style={{ color: 'var(--ck-text)' }}>
                                    This renames the JAR to .disabled and requires a restart to take effect.
                                    Reversible via rollback.
                                </span>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    disabled={pending || hasPending}
                                    onClick={() => post(`/plugins/${plugin.filename}/disable`)}
                                    data-test="confirm-disable"
                                >
                                    Confirm disable
                                </Button>
                                <Button type="button" variant="outline" size="sm" onClick={() => setConfirming(null)}>
                                    Cancel
                                </Button>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={hasPending}
                                onClick={() => setConfirming('disable')}
                                data-test="disable-plugin"
                            >
                                Disable
                            </Button>
                        )
                    ) : (
                        <span className="text-[12.5px]" style={{ color: 'var(--ck-text-2)' }}>
                            Disabled — re-enable by rolling back its disable operation in history below.
                        </span>
                    )}

                    {confirming === 'remove' ? (
                        <div
                            className="flex flex-wrap items-center gap-[10px] rounded-[8px] border px-[12px] py-[10px]"
                            style={ckSubtleSurfaceStyle('danger')}
                            data-test="remove-confirm-panel"
                        >
                            <span className="text-[12px]" style={{ color: 'var(--ck-text)' }}>
                                This moves the JAR to preserved rollback storage (never a bare delete) and
                                requires a restart to take effect. Reversible via rollback.
                            </span>
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                disabled={pending || hasPending}
                                onClick={() => post(`/plugins/${plugin.filename}/remove`)}
                                data-test="confirm-remove"
                            >
                                Confirm remove
                            </Button>
                            <Button type="button" variant="outline" size="sm" onClick={() => setConfirming(null)}>
                                Cancel
                            </Button>
                        </div>
                    ) : (
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={hasPending}
                            onClick={() => setConfirming('remove')}
                            data-test="remove-plugin"
                        >
                            Remove
                        </Button>
                    )}
                </div>
            </section>

            {rollbackArtifacts.length > 0 && (
                <section
                    className="mt-[16px] rounded-[12px] border p-[16px]"
                    style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
                    data-test="rollback-artifacts"
                >
                    <h2 className="mb-[10px] text-[12px] font-bold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                        Preserved artifacts (rollback)
                    </h2>
                    <ul className="grid gap-[8px]">
                        {rollbackArtifacts.map((artifact) =>
                            confirmingRestoreId === artifact.id ? (
                                <li
                                    key={artifact.id}
                                    className="flex flex-wrap items-center gap-[10px] rounded-[8px] border px-[12px] py-[10px] text-[12px]"
                                    style={ckSubtleSurfaceStyle('warning')}
                                    data-test="restore-confirm-panel"
                                >
                                    <span style={{ color: 'var(--ck-text)' }}>
                                        Replaces the currently installed {plugin.filename} with this preserved
                                        version ({artifact.sha256.slice(0, 12)}…, {artifact.reason}); the current
                                        version will be preserved for rollback.
                                    </span>
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="sm"
                                        disabled={pending || hasPending}
                                        onClick={() => post(`/plugins/${plugin.filename}/rollback`, { rollback_artifact_id: artifact.id })}
                                        data-test="confirm-rollback-to-artifact"
                                    >
                                        {pending ? 'Restoring…' : 'Confirm restore'}
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setConfirmingRestoreId(null)}>
                                        Cancel
                                    </Button>
                                </li>
                            ) : (
                                <li
                                    key={artifact.id}
                                    className="flex flex-wrap items-center justify-between gap-[10px] rounded-[7px] border px-[11px] py-[8px] text-[12px]"
                                    style={{ borderColor: 'var(--ck-border)' }}
                                >
                                    <span style={{ color: 'var(--ck-text)' }}>
                                        <span className="font-mono text-[11px]" style={{ color: 'var(--ck-text-2)' }}>
                                            {artifact.sha256.slice(0, 12)}…
                                        </span>{' '}
                                        ({artifact.reason}, {artifact.createdAt})
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={pending || hasPending}
                                        onClick={() => setConfirmingRestoreId(artifact.id)}
                                        data-test="rollback-to-artifact"
                                    >
                                        Restore this version
                                    </Button>
                                </li>
                            ),
                        )}
                    </ul>
                </section>
            )}

            <section
                className="mt-[16px] rounded-[12px] border p-[16px]"
                style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
            >
                <h2 className="mb-[10px] text-[12px] font-bold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                    History
                </h2>
                {history.length === 0 ? (
                    <p className="text-[12.5px]" style={{ color: 'var(--ck-text-2)' }}>
                        No lifecycle operations recorded yet.
                    </p>
                ) : (
                    <ul className="grid gap-[6px]">
                        {history.map((operation) => (
                            <li key={operation.id} className="flex items-center justify-between gap-[8px] text-[12.5px]">
                                <Link
                                    href={`/plugins/operations/${operation.id}`}
                                    className="font-mono underline"
                                    style={{ color: 'var(--ck-accent)' }}
                                >
                                    {operation.type}
                                </Link>
                                <span style={{ color: 'var(--ck-text-2)' }}>{operation.status}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </AppShell>
    );
}
