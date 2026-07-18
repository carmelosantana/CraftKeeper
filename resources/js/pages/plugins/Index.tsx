import { Head, Link } from '@inertiajs/react';
import { PageState } from '@/components/craftkeeper/PageState';
import { ProvenanceBadge } from '@/components/craftkeeper/ProvenanceBadge';
import type { ProvenanceSource } from '@/components/craftkeeper/ProvenanceBadge';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { CompatibilityEvidence } from '@/features/plugins/CompatibilityEvidence';
import { AppShell } from '@/layouts/AppShell';
import type { PluginIndexProps, PluginInstallationDTO } from '@/types/plugins';

/**
 * Installed inventory — Task 15's `Installed` tab of the Plugin
 * Discovery/Management surface. Disk state, source, compatibility
 * evidence, and any pending (proposed/approved/running) operation are
 * shown per plugin; guarded actions (update/disable/remove) live on the
 * detail page (Show.tsx), reached via each row's "Manage" link, per the
 * plan's "consequences before confirmation" pattern — nothing here is a
 * one-click destructive action.
 */
function PluginRow({ plugin }: { plugin: PluginInstallationDTO }) {
    return (
        <li
            data-test="plugin-row"
            className="rounded-[12px] border p-[16px]"
            style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
        >
            <div className="flex flex-wrap items-start justify-between gap-[10px]">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-[8px]">
                        <h2 className="text-[15px] font-bold" style={{ color: 'var(--ck-text)' }}>
                            {plugin.name ?? plugin.filename}
                        </h2>
                        <ProvenanceBadge source={plugin.provenance as ProvenanceSource} />
                        {!plugin.enabled && <StatusText status="offline" label="Disabled" />}
                        {plugin.missingSince && <StatusText status="failed" label="Missing on disk" />}
                        {plugin.duplicateName && <StatusText status="degraded" label="Duplicate name" />}
                    </div>
                    <div
                        className="mt-[3px] font-mono text-[11.5px]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        {plugin.relativePath} {plugin.version ? `· v${plugin.version}` : ''}
                    </div>
                </div>
                <Link
                    href={`/plugins/${plugin.filename}`}
                    className="shrink-0"
                >
                    <Button type="button" variant="outline" size="sm" data-test="plugin-manage">
                        Manage
                    </Button>
                </Link>
            </div>

            {plugin.compatibilityState && (
                <div className="mt-[12px]">
                    <CompatibilityEvidence
                        state={plugin.compatibilityState}
                        evidence={plugin.compatibilityEvidence}
                        variant="compact"
                    />
                </div>
            )}

            {plugin.pendingOperation && (
                <div className="mt-[12px] flex items-center gap-[8px]">
                    <RestartRequired
                        variant="chip"
                        label={`Pending: ${plugin.pendingOperation.type}`}
                    />
                    <Link
                        href={`/plugins/operations/${plugin.pendingOperation.id}`}
                        className="text-[12px] font-semibold underline"
                        style={{ color: 'var(--ck-accent)' }}
                    >
                        Review
                    </Link>
                </div>
            )}
        </li>
    );
}

export default function PluginIndex({ plugins }: PluginIndexProps) {
    return (
        <AppShell>
            <Head title="Plugins" />

            <header className="mb-[18px] flex flex-wrap items-center justify-between gap-[12px]">
                <h1 className="text-[20px] font-bold" style={{ color: 'var(--ck-text)' }}>
                    Plugins
                </h1>
                <div className="flex gap-[10px]">
                    <Link href="/plugins/upload">
                        <Button type="button" variant="outline" data-test="upload-jar">
                            Upload JAR
                        </Button>
                    </Link>
                    <Link href="/plugins/discover">
                        <Button type="button" data-test="discover-plugins">
                            Discover
                        </Button>
                    </Link>
                </div>
            </header>

            {plugins.length === 0 ? (
                <PageState
                    state="empty"
                    title="No plugins installed"
                    description="Discover a plugin from the catalog or upload a JAR to get started."
                />
            ) : (
                <ul className="grid gap-[12px]" data-test="plugin-list">
                    {plugins.map((plugin) => (
                        <PluginRow key={plugin.relativePath} plugin={plugin} />
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
