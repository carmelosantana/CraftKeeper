import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { AppShell } from '@/layouts/AppShell';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Server',
        href: '/settings/server',
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'AI Providers',
        href: '/settings/ai',
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
    {
        title: 'Analytics',
        href: '/settings/analytics',
        icon: null,
    },
    {
        title: 'Backups',
        href: '/settings/backups',
        icon: null,
    },
    {
        title: 'API',
        href: '/integrations/api',
        icon: null,
    },
    {
        title: 'MCP',
        href: '/integrations/mcp',
        icon: null,
    },
    {
        title: 'Advanced',
        href: '/settings/advanced',
        icon: null,
    },
];

/**
 * Every settings page renders inside CraftKeeper's own AppShell, exactly as
 * `/settings`, `/overview`, and the rest of the application do.
 *
 * It used not to. `resources/js/app.tsx` paired this layout with the Laravel
 * starter kit's `AppLayout`, so opening any settings page swapped the entire
 * chrome: a different sidebar (with its own "Toggle sidebar" control), the
 * starter kit's generic "Manage your profile and account settings" heading,
 * and a second navigation list stacked beneath CraftKeeper's own. Walking
 * from Settings into Server settings visibly left the product — the same
 * drift that left a stock Laravel welcome page sitting on `/`.
 *
 * The section nav below stays: these pages really are a group, and the
 * shell's top-level nav points at `/settings` as a whole. It uses the
 * `--ck-*` tokens so it reads as part of the shell rather than bolted onto it.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <AppShell>
            <header className="mb-[18px]">
                <h1
                    className="text-[20px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Settings
                </h1>
                <p
                    className="mt-[3px] text-[12.5px]"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Every configuration section in one place.
                </p>
            </header>

            <div className="flex flex-col gap-[18px] lg:flex-row lg:gap-[24px]">
                <aside className="w-full lg:w-[184px] lg:shrink-0">
                    <nav
                        className="flex flex-row flex-wrap gap-[2px] lg:flex-col lg:flex-nowrap"
                        aria-label="Settings sections"
                        data-test="settings-section-nav"
                    >
                        {sidebarNavItems.map((item, index) => {
                            const current = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={`${toUrl(item.href)}-${index}`}
                                    href={item.href}
                                    aria-current={current ? 'page' : undefined}
                                    className={cn(
                                        'rounded-[8px] px-[10px] py-[6px] text-[12.5px] transition-colors',
                                    )}
                                    style={{
                                        backgroundColor: current
                                            ? 'var(--ck-surface-2)'
                                            : 'transparent',
                                        color: current
                                            ? 'var(--ck-text)'
                                            : 'var(--ck-text-2)',
                                    }}
                                >
                                    {item.title}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <section className="min-w-0 flex-1 space-y-[24px]">
                    {children}
                </section>
            </div>
        </AppShell>
    );
}
