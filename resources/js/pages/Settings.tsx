import { Head, Link } from '@inertiajs/react';
import { AppShell } from '@/layouts/AppShell';
import type { SettingsIndexPageProps } from '@/types/settings';

/**
 * Task 19's Settings index — the landing page AppShell's "Settings" nav
 * item promises at `/settings` (matching `/integrations`'s own overview),
 * replacing the plain redirect-to-profile that stood in for it before
 * this task. Links out to all nine settings sections; Profile/Security/
 * Appearance and the API/MCP integration pages already existed (Tasks 3,
 * 4, 17, 18) and are only linked from here, never duplicated.
 */
export default function Settings({ sections, summary }: SettingsIndexPageProps) {
    return (
        <AppShell>
            <Head title="Settings" />

            <header className="mb-[18px]">
                <h1 className="text-[20px] font-bold" style={{ color: 'var(--ck-text)' }}>
                    Settings
                </h1>
                <p className="mt-[3px] text-[12.5px]" style={{ color: 'var(--ck-text-2)' }}>
                    Every configuration section in one place.
                </p>
            </header>

            <dl
                className="mb-[24px] grid grid-cols-2 gap-[10px] rounded-[12px] border p-[16px] sm:grid-cols-4"
                style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
                data-test="settings-summary"
            >
                <SummaryStat label="AI provider" value={summary.aiConfigured ? 'Configured' : 'Not configured'} />
                <SummaryStat label="Analytics" value={summary.analyticsActive ? 'Active' : 'Inactive'} />
                <SummaryStat label="API tokens" value={String(summary.apiTokenCount)} />
                <SummaryStat label="MCP connections" value={String(summary.mcpGrantCount)} />
            </dl>

            <ul className="grid gap-[10px] sm:grid-cols-2 lg:grid-cols-3" data-test="settings-section-list">
                {sections.map((section) => (
                    <li key={section.key}>
                        <Link
                            href={section.href}
                            data-test={`settings-section-${section.key}`}
                            className="block h-full rounded-[12px] border p-[16px] transition-colors hover:border-[var(--ck-border-strong)]"
                            style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
                        >
                            <span className="block text-[13.5px] font-bold" style={{ color: 'var(--ck-text)' }}>
                                {section.label}
                            </span>
                            <span className="mt-[4px] block text-[12px]" style={{ color: 'var(--ck-text-2)' }}>
                                {section.description}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </AppShell>
    );
}

function SummaryStat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-[10.5px] font-semibold tracking-wide uppercase" style={{ color: 'var(--ck-text-2)' }}>
                {label}
            </dt>
            <dd className="mt-[2px] text-[14px] font-bold" style={{ color: 'var(--ck-text)' }}>
                {value}
            </dd>
        </div>
    );
}
