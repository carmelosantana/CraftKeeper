import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import { Input } from '@/components/ui/input';
import { ConfigPreview } from '@/features/config/ConfigPreview';
import { AppShell } from '@/layouts/AppShell';
import type { ConfigIndexProps } from '@/types/config';

const CATEGORY_TITLES: Record<string, string> = {
    server: 'Server',
    paper: 'Paper',
    geyser: 'Geyser',
    floodgate: 'Floodgate',
    plugin: 'Plugins',
    other: 'Other',
};

/**
 * Configuration inventory — grouped by Server, Paper, Geyser/Floodgate,
 * and plugin, per the plan. Search operates only on names/paths/schema
 * labels/plugin names (App\Http\Controllers\ConfigController::index()
 * enforces this server-side) — it can never match, or leak, secret
 * content.
 */
export default function ConfigIndex({ query, groups, total }: ConfigIndexProps) {
    const [search, setSearch] = useState(query);

    function runSearch(value: string) {
        setSearch(value);
        router.get(
            '/configurations',
            value ? { q: value } : {},
            { preserveState: true, replace: true },
        );
    }

    const categories = Object.keys(groups);

    return (
        <AppShell>
            <Head title="Configurations" />

            <header className="mb-[18px]">
                <h1
                    className="text-[20px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Configurations
                </h1>
                <p className="mt-[2px] text-[13px]" style={{ color: 'var(--ck-text-2)' }}>
                    {total} configuration file{total === 1 ? '' : 's'} discovered
                    on the mounted server.
                </p>
            </header>

            <div className="mb-[20px] max-w-[420px]">
                <label htmlFor="config-search" className="sr-only">
                    Search configurations
                </label>
                <Input
                    id="config-search"
                    type="search"
                    placeholder="Search by name, path, or plugin…"
                    value={search}
                    onChange={(event) => runSearch(event.target.value)}
                    data-test="config-search"
                />
            </div>

            {categories.length === 0 ? (
                <PageState
                    state="empty"
                    title={
                        query
                            ? 'No configurations match your search'
                            : 'No configurations discovered yet'
                    }
                    description={
                        query
                            ? 'Try a different name, path, or plugin.'
                            : 'CraftKeeper could not find any recognized configuration files under the mounted Minecraft directory.'
                    }
                />
            ) : (
                <div className="grid gap-[26px]">
                    {categories.map((category) => (
                        <section key={category} className="grid gap-[10px]">
                            <h2
                                className="text-[11px] font-bold tracking-wide uppercase"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                {CATEGORY_TITLES[category] ?? category}
                            </h2>
                            <div className="grid gap-[10px] sm:grid-cols-2 xl:grid-cols-3">
                                {groups[category].map((item) => (
                                    <ConfigPreview key={item.path} item={item} />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}
        </AppShell>
    );
}
