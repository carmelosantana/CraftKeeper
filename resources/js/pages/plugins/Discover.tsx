import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { ProvenanceBadge } from '@/components/craftkeeper/ProvenanceBadge';
import type { ProvenanceSource } from '@/components/craftkeeper/ProvenanceBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import { AppShell } from '@/layouts/AppShell';
import type { CatalogReleaseDTO, PluginDiscoverProps } from '@/types/plugins';

const SOURCES: Array<{ key: string; label: string }> = [
    { key: 'catalog', label: 'Catalog' },
    { key: 'hangar', label: 'Hangar' },
    { key: 'modrinth', label: 'Modrinth' },
];

function compatibilityBadge(item: CatalogReleaseDTO) {
    const positive = item.compatibilityEvidence.find((e) => e.supportsCompatibility === true);
    const negative = item.compatibilityEvidence.find((e) => e.supportsCompatibility === false);

    if (positive) {
        return { label: '✓ Compatible', tone: 'success' as const };
    }

    if (negative) {
        return { label: 'Possibly incompatible', tone: 'warning' as const };
    }

    return { label: 'Untested', tone: 'neutral' as const };
}

function ResultCard({ item }: { item: CatalogReleaseDTO }) {
    const [pending, setPending] = useState(false);
    const badge = compatibilityBadge(item);

    function install() {
        router.post(
            '/plugins/install',
            {
                source: item.source.charAt(0).toUpperCase() + item.source.slice(1),
                projectId: item.projectId,
                // Already installed: check the source's own notion of
                // "latest" (App\Catalog\Data\PluginReleaseId's null-version
                // convention) rather than blindly re-proposing the exact
                // summary version this search result happens to show —
                // the controller resolves this into an UPDATE proposal
                // against the existing installation
                // (App\Http\Controllers\PluginController::proposeInstall()).
                version: item.installed ? null : item.version,
            },
            { onStart: () => setPending(true), onFinish: () => setPending(false) },
        );
    }

    return (
        <div
            data-test="discover-result"
            className="flex flex-col gap-[12px] rounded-[10px] border p-[16px]"
            style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
        >
            <div className="flex items-start justify-between gap-[10px]">
                <div className="min-w-0">
                    <div className="text-[15px] font-bold" style={{ color: 'var(--ck-text)' }}>
                        {item.name}
                    </div>
                    <div className="mt-[1px] text-[11.5px]" style={{ color: 'var(--ck-text-3)' }}>
                        {item.version}
                    </div>
                </div>
                <ProvenanceBadge source={item.source as ProvenanceSource} />
            </div>

            <p className="text-[12.5px] leading-[1.5]" style={{ color: 'var(--ck-text-2)' }}>
                {item.description}
            </p>

            <div className="flex flex-wrap gap-[6px]">
                <span
                    className="rounded-[4px] px-[8px] py-[3px] text-[10px] font-semibold"
                    style={ckSubtleSurfaceStyle(badge.tone)}
                >
                    {badge.label}
                </span>
                {item.license && (
                    <span
                        className="rounded-[4px] px-[8px] py-[3px] text-[10px] font-semibold"
                        style={{ backgroundColor: 'var(--ck-surface-2)', color: 'var(--ck-text-2)' }}
                    >
                        {item.license}
                    </span>
                )}
                {item.withdrawn && (
                    <span
                        className="rounded-[4px] px-[8px] py-[3px] text-[10px] font-semibold"
                        style={ckSubtleSurfaceStyle('danger')}
                    >
                        Withdrawn
                    </span>
                )}
            </div>

            <div
                className="flex items-center justify-between border-t pt-[12px]"
                style={{ borderColor: 'var(--ck-border)' }}
            >
                <a
                    href={item.projectUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="text-[11px] font-medium underline"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Project page ↗
                </a>
                <div className="flex items-center gap-[8px]">
                    {item.installed && (
                        <span className="text-[11px] font-semibold" style={{ color: 'var(--ck-text-2)' }}>
                            Installed
                        </span>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        variant={item.installed ? 'outline' : 'default'}
                        onClick={install}
                        disabled={pending || item.withdrawn}
                        data-test={item.installed ? 'discover-update' : 'discover-install'}
                    >
                        {pending ? 'Preparing…' : item.installed ? 'Check for update' : 'Install'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

export default function PluginDiscover({ query, items, sourceResults }: PluginDiscoverProps) {
    const [q, setQ] = useState(query);
    const [enabledSources, setEnabledSources] = useState<Record<string, boolean>>({
        catalog: true,
        hangar: true,
        modrinth: true,
    });

    const filtered = useMemo(
        () => items.filter((item) => enabledSources[item.source] !== false),
        [items, enabledSources],
    );

    function search() {
        router.get('/plugins/discover', { q }, { preserveState: true });
    }

    const degradedSources = sourceResults.filter((r) => r.degraded);

    return (
        <AppShell>
            <Head title="Discover plugins" />

            <header className="mb-[18px]">
                <h1 className="text-[20px] font-bold" style={{ color: 'var(--ck-text)' }}>
                    Discover plugins
                </h1>
                <nav className="mt-[4px] flex gap-[16px] text-[13px]" style={{ color: 'var(--ck-text-2)' }}>
                    <Link href="/plugins">Installed</Link>
                    <span className="font-bold" style={{ color: 'var(--ck-text)' }}>
                        Discover
                    </span>
                    <Link href="/plugins/upload">Upload JAR</Link>
                </nav>
            </header>

            {degradedSources.length > 0 && (
                <PageState
                    state="marketplace-unavailable"
                    className="mb-[16px]"
                    description={`${degradedSources.map((s) => s.source).join(', ')} could not be reached — showing results from the remaining sources.`}
                />
            )}

            <div className="flex flex-wrap gap-[20px]">
                <aside className="w-full shrink-0 sm:w-[180px]" data-test="discover-filters">
                    <div
                        className="mb-[8px] text-[10px] font-bold tracking-wide uppercase"
                        // Task 20 fix pass: this heading sits directly on
                        // --ck-bg (no --ck-surface/--ck-elevated card
                        // wraps this <aside>), where --ck-text-3 measures
                        // only 4.15:1 in light theme. --ck-text-2 clears
                        // AA against --ck-bg in both themes.
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Source
                    </div>
                    <div className="grid gap-[6px]">
                        {SOURCES.map((source) => (
                            <label
                                key={source.key}
                                className="flex cursor-pointer items-center gap-[8px] text-[12.5px]"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                <input
                                    type="checkbox"
                                    checked={enabledSources[source.key] !== false}
                                    onChange={(e) =>
                                        setEnabledSources((prev) => ({ ...prev, [source.key]: e.target.checked }))
                                    }
                                />
                                {source.label}
                            </label>
                        ))}
                    </div>
                </aside>

                <div className="min-w-0 flex-1">
                    <div className="mb-[14px] flex gap-[10px]">
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && search()}
                            placeholder="Search plugins…"
                            data-test="discover-search"
                        />
                        <Button type="button" onClick={search} data-test="discover-search-button">
                            Search
                        </Button>
                    </div>

                    {filtered.length === 0 ? (
                        <PageState
                            state="empty"
                            title="No results"
                            description="Try a different search term or enable more sources."
                        />
                    ) : (
                        <div
                            className="grid gap-[16px]"
                            style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))' }}
                            data-test="discover-results"
                        >
                            {filtered.map((item) => (
                                <ResultCard key={`${item.source}:${item.projectId}`} item={item} />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AppShell>
    );
}
