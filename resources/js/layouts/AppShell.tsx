import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import {
    STATUS_BADGE_META,
    StatusGlyph,
} from '@/components/craftkeeper/StatusBadge';
import type { StatusBadgeStatus } from '@/components/craftkeeper/StatusBadge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { CommandPalette } from '@/features/command-palette/CommandPalette';
import { CkThemeProvider, useCkTheme } from '@/hooks/use-ck-theme';
import type { CkAccentName, CkThemeName } from '@/hooks/use-ck-theme';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { IsCurrentUrlFn } from '@/hooks/use-current-url';

/**
 * `Design/handoff/pages.json` → `primaryNavigation`. This is the single
 * navigation contract every CraftKeeper page shares — built once here,
 * never copied per page (see `docs/superpowers/plans/2026-07-17-craftkeeper-v1.md`:
 * "Build one AppShell; do not copy the sidebar/header markup from each
 * mockup.").
 */
export const primaryNavigation = [
    'Overview',
    'Server',
    'Configurations',
    'Plugins',
    'Assistant',
    'Activity',
    'Integrations',
    'Settings',
] as const;

export type PrimaryNavigationLabel = (typeof primaryNavigation)[number];

export interface AppShellNavItem {
    label: string;
    href: string;
    /** Small numeric/text badge, e.g. a pending-operation count. */
    badge?: string | number;
}

const DEFAULT_NAVIGATION: AppShellNavItem[] = primaryNavigation.map(
    (label) => ({ label, href: `/${label.toLowerCase()}` }),
);

export interface AppShellServerIdentity {
    name: string;
    address: string;
    version: string;
    status: StatusBadgeStatus;
    playersOnline: number;
    playersMax: number;
}

const DEFAULT_SERVER: AppShellServerIdentity = {
    name: 'Survival',
    address: 'mc.example.net',
    version: 'Paper 1.21.4',
    status: 'online',
    playersOnline: 3,
    playersMax: 40,
};

export interface AppShellUser {
    name: string;
    totpEnabled: boolean;
}

const DEFAULT_USER: AppShellUser = { name: 'admin', totpEnabled: true };

export interface AppShellProps {
    children: ReactNode;
    navigation?: AppShellNavItem[];
    server?: AppShellServerIdentity;
    user?: AppShellUser;
    /** Shows the shared restart-required indicator (chip in the desktop
     * top bar, banner in the mobile content area — see
     * `RestartRequired`). Operational state is honest and specific: a
     * pending restart is never hidden. */
    pendingRestart?: boolean;
    defaultTheme?: CkThemeName;
    defaultAccent?: CkAccentName;
}

export function AppShell(props: AppShellProps) {
    return (
        <CkThemeProvider
            defaultTheme={props.defaultTheme}
            defaultAccent={props.defaultAccent}
        >
            <AppShellChrome {...props} />
        </CkThemeProvider>
    );
}

function NavGlyph({ active }: { active: boolean }) {
    return (
        <span
            aria-hidden="true"
            style={
                active
                    ? {
                          width: 16,
                          height: 16,
                          borderRadius: 3,
                          backgroundColor: 'currentColor',
                          opacity: 0.9,
                          flex: 'none',
                      }
                    : {
                          width: 16,
                          height: 16,
                          borderRadius: 3,
                          border: '1.5px solid var(--ck-text-3)',
                          flex: 'none',
                      }
            }
        />
    );
}

function ShellNav({
    navigation,
    isCurrentUrl,
    onNavigate,
}: {
    navigation: AppShellNavItem[];
    isCurrentUrl: IsCurrentUrlFn;
    onNavigate?: () => void;
}) {
    return (
        <div
            role="list"
            aria-label="Primary sections"
            className="flex flex-1 flex-col gap-[2px] overflow-y-auto px-3 py-1"
        >
            {navigation.map((item) => {
                const active = isCurrentUrl(item.href);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        onClick={onNavigate}
                        role="listitem"
                        aria-current={active ? 'page' : undefined}
                        className="flex items-center gap-[11px] rounded-[7px] px-[11px] py-[9px] font-sans text-[13px]"
                        style={
                            active
                                ? {
                                      backgroundColor:
                                          'color-mix(in srgb, var(--ck-accent) 16%, transparent)',
                                      color: 'var(--ck-accent-hover)',
                                      fontWeight: 600,
                                  }
                                : { color: 'var(--ck-text-2)', fontWeight: 500 }
                        }
                    >
                        <NavGlyph active={active} />
                        <span>{item.label}</span>
                        {item.badge !== undefined && (
                            <span
                                className="ml-auto rounded-[4px] px-[6px] py-[1px] text-[10px] font-semibold"
                                style={{
                                    backgroundColor:
                                        'color-mix(in srgb, var(--ck-warning) 18%, transparent)',
                                    color: 'var(--ck-warning)',
                                }}
                            >
                                {item.badge}
                            </span>
                        )}
                    </Link>
                );
            })}
        </div>
    );
}

function ServerIdentityCard({ server }: { server: AppShellServerIdentity }) {
    const meta = STATUS_BADGE_META[server.status];

    return (
        <div
            className="mx-3 my-[14px] rounded-[8px] border px-[13px] py-[12px]"
            style={{
                backgroundColor: 'var(--ck-elevated)',
                borderColor: 'var(--ck-border)',
            }}
        >
            <div className="flex items-center gap-2">
                <span
                    className="min-w-0 flex-1 truncate text-[13px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    {server.name}
                </span>
                {/* Status must never rely on color alone: pair the same
                    per-status shape glyph StatusBadge uses elsewhere
                    (square-pulse for online, triangle for
                    pending-restart, etc.) with a visible text label,
                    rather than color alone. This reuses StatusGlyph
                    directly rather than the full StatusBadge chip: the
                    chip's tinted fill is only contrast-verified against
                    --ck-surface (see design-tokens.json), and this card
                    sits on --ck-elevated — --ck-text-2 is the token
                    already proven AA-safe on --ck-elevated (see the
                    address line below). */}
                <span
                    role="status"
                    aria-label={meta.label}
                    className="inline-flex shrink-0 items-center gap-[5px] text-[10.5px] font-semibold"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    <StatusGlyph tone={meta.tone} glyph={meta.glyph} />
                    {meta.label}
                </span>
            </div>
            <div
                className="mt-1 truncate font-mono text-[10.5px]"
                // --ck-text-3 on --ck-elevated falls under the 4.5:1 AA
                // threshold for normal text (it's only validated against
                // --ck-surface in design-tokens.json) — --ck-text-2 holds
                // AA here.
                style={{ color: 'var(--ck-text-2)' }}
            >
                {server.address} · {server.version}
            </div>
            <div
                className="mt-[9px] flex items-center gap-[6px] text-[11px] font-semibold"
                style={{ color: 'var(--ck-text-2)' }}
            >
                <span
                    aria-hidden="true"
                    style={{
                        width: 6,
                        height: 6,
                        borderRadius: 1,
                        backgroundColor: 'var(--ck-success)',
                    }}
                />
                {server.playersOnline} / {server.playersMax} online
            </div>
        </div>
    );
}

function AppShellChrome({
    children,
    navigation = DEFAULT_NAVIGATION,
    server = DEFAULT_SERVER,
    user = DEFAULT_USER,
    pendingRestart = false,
}: AppShellProps) {
    const { theme, accent, setTheme } = useCkTheme();
    const { isCurrentUrl } = useCurrentUrl();
    const [mobileNavOpen, setMobileNavOpen] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                setPaletteOpen((open) => !open);
            }
        }

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    return (
        <div
            data-theme={theme}
            data-accent={accent}
            className="flex min-h-screen w-full font-sans"
            style={{ backgroundColor: 'var(--ck-bg)', color: 'var(--ck-text)' }}
        >
            <a href="#ck-main-content" className="ck-skip-link">
                <span
                    className="rounded-[6px] px-[14px] py-[9px] text-sm font-semibold"
                    style={{
                        backgroundColor: 'var(--ck-accent)',
                        color: 'var(--ck-accent-fg)',
                    }}
                >
                    Skip to content
                </span>
            </a>

            {/* Desktop sidebar (>=1024px) */}
            <nav
                aria-label="Primary"
                className="hidden lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-[236px] lg:flex-none lg:flex-col lg:border-r"
                style={{
                    backgroundColor: 'var(--ck-surface)',
                    borderColor: 'var(--ck-border)',
                }}
            >
                <BrandMark />
                <ServerIdentityCard server={server} />
                <ShellNav navigation={navigation} isCurrentUrl={isCurrentUrl} />
                <AdminMenu user={user} />
            </nav>

            <div className="flex min-w-0 flex-1 flex-col">
                <header
                    className="sticky top-0 z-20 flex items-center gap-[10px] border-b px-[16px] py-[10px] backdrop-blur-sm lg:gap-[14px] lg:px-[26px] lg:py-[11px]"
                    style={{
                        backgroundColor:
                            'color-mix(in srgb, var(--ck-bg) 82%, transparent)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <button
                        type="button"
                        aria-label="Open navigation"
                        onClick={() => setMobileNavOpen(true)}
                        className="flex flex-none items-center justify-center rounded-[7px] border lg:hidden"
                        style={{
                            borderColor: 'var(--ck-border-strong)',
                            // Design system's own declared minimum mobile
                            // hit target (resources/css/app.css) — not a
                            // magic 44px.
                            minWidth: 'var(--ck-min-mobile-hit-target)',
                            minHeight: 'var(--ck-min-mobile-hit-target)',
                        }}
                    >
                        <span
                            aria-hidden="true"
                            className="flex flex-col gap-[3px]"
                        >
                            <span
                                className="h-[2px] w-[16px] rounded-full"
                                style={{ backgroundColor: 'var(--ck-text-2)' }}
                            />
                            <span
                                className="h-[2px] w-[16px] rounded-full"
                                style={{ backgroundColor: 'var(--ck-text-2)' }}
                            />
                            <span
                                className="h-[2px] w-[16px] rounded-full"
                                style={{ backgroundColor: 'var(--ck-text-2)' }}
                            />
                        </span>
                    </button>

                    <button
                        type="button"
                        onClick={() => setPaletteOpen(true)}
                        className="hidden max-w-[38vw] flex-1 items-center gap-[10px] rounded-[7px] border px-[12px] py-[8px] text-left text-[13px] font-medium sm:flex lg:w-[340px] lg:flex-none"
                        style={{
                            borderColor: 'var(--ck-border-strong)',
                            backgroundColor: 'var(--ck-surface)',
                            // --ck-text-2, not --ck-text-3: this is
                            // readable body text, not metadata, and needs
                            // the full 4.5:1 AA contrast ratio.
                            color: 'var(--ck-text-2)',
                        }}
                    >
                        <span
                            aria-hidden="true"
                            style={{
                                width: 13,
                                height: 13,
                                borderRadius: 3,
                                border: '1.5px solid var(--ck-text-3)',
                                flex: 'none',
                            }}
                        />
                        <span className="flex-1 truncate">
                            Search or run a command…
                        </span>
                        <kbd
                            className="rounded-[4px] border px-[6px] py-[2px] font-mono text-[10px] font-semibold"
                            style={{
                                backgroundColor: 'var(--ck-surface-2)',
                                borderColor: 'var(--ck-border-strong)',
                                color: 'var(--ck-text-2)',
                            }}
                        >
                            ⌘K
                        </kbd>
                    </button>

                    <button
                        type="button"
                        aria-label="Search or run a command"
                        onClick={() => setPaletteOpen(true)}
                        className="flex flex-none items-center justify-center rounded-[7px] border sm:hidden"
                        style={{
                            borderColor: 'var(--ck-border-strong)',
                            minWidth: 'var(--ck-min-mobile-hit-target)',
                            minHeight: 'var(--ck-min-mobile-hit-target)',
                        }}
                    >
                        <span
                            aria-hidden="true"
                            style={{
                                width: 13,
                                height: 13,
                                borderRadius: 3,
                                border: '1.5px solid var(--ck-text-3)',
                            }}
                        />
                    </button>

                    <div className="flex-1" />

                    {pendingRestart && (
                        <RestartRequired
                            variant="chip"
                            className="hidden lg:inline-flex"
                        />
                    )}

                    <div
                        className="hidden items-center gap-1 rounded-[7px] border p-1 lg:flex"
                        style={{
                            backgroundColor: 'var(--ck-surface)',
                            borderColor: 'var(--ck-border)',
                        }}
                    >
                        {(['dark', 'light'] as const).map((mode) => (
                            <button
                                key={mode}
                                type="button"
                                aria-pressed={theme === mode}
                                onClick={() => setTheme(mode)}
                                className="rounded-[5px] px-[10px] py-[4px] text-[11px] font-semibold capitalize"
                                style={
                                    theme === mode
                                        ? {
                                              backgroundColor:
                                                  'var(--ck-accent)',
                                              color: 'var(--ck-accent-fg)',
                                              border: '1px solid var(--ck-accent)',
                                          }
                                        : {
                                              color: 'var(--ck-text-2)',
                                              border: '1px solid transparent',
                                          }
                                }
                            >
                                {mode}
                            </button>
                        ))}
                    </div>

                    <button
                        type="button"
                        aria-label="Notifications"
                        className="hidden size-[34px] flex-none items-center justify-center rounded-[7px] border lg:flex"
                        style={{ borderColor: 'var(--ck-border-strong)' }}
                    >
                        <span
                            aria-hidden="true"
                            style={{
                                width: 12,
                                height: 12,
                                border: '1.5px solid var(--ck-text-2)',
                                borderBottom: 'none',
                                borderRadius: '6px 6px 0 0',
                            }}
                        />
                    </button>

                    <button
                        type="button"
                        className="hidden h-[34px] flex-none items-center gap-[7px] rounded-[7px] border px-[13px] text-[12px] font-semibold lg:inline-flex"
                        style={{
                            borderColor: 'var(--ck-accent)',
                            backgroundColor:
                                'color-mix(in srgb, var(--ck-accent) 14%, transparent)',
                            color: 'var(--ck-accent-hover)',
                        }}
                    >
                        <span
                            aria-hidden="true"
                            className="flex size-4 items-center justify-center rounded-[4px] text-[8px] font-bold"
                            style={{
                                // A light tint background + the same
                                // token as foreground reads fine at
                                // regular size, but at 8px bold it falls
                                // below 4.5:1 (aria-hidden doesn't exempt
                                // it — sighted users still see it). A
                                // solid fill with --ck-bg text holds AA.
                                backgroundColor:
                                    'var(--ck-provenance-ai-provider)',
                                color: 'var(--ck-bg)',
                            }}
                        >
                            AI
                        </span>
                        Ask
                    </button>
                </header>

                <main
                    id="ck-main-content"
                    tabIndex={-1}
                    className="mx-auto w-full max-w-[1160px] flex-1 px-[16px] py-[20px] outline-none lg:px-[26px] lg:py-[26px]"
                >
                    {pendingRestart && (
                        <RestartRequired
                            variant="banner"
                            className="mb-[18px] lg:hidden"
                        />
                    )}
                    {children}
                </main>
            </div>

            {/* Mobile navigation drawer (<1024px). Radix Dialog (via Sheet)
                already provides focus trapping, Escape dismissal, and
                focus return. */}
            <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
                <SheetContent
                    side="left"
                    className="w-[82%] gap-0 p-0 sm:max-w-[300px]"
                    style={{
                        backgroundColor: 'var(--ck-surface)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <SheetTitle className="sr-only">Navigation</SheetTitle>
                    <SheetDescription className="sr-only">
                        Primary sections and server status
                    </SheetDescription>
                    <BrandMark />
                    <ServerIdentityCard server={server} />
                    <ShellNav
                        navigation={navigation}
                        isCurrentUrl={isCurrentUrl}
                        onNavigate={() => setMobileNavOpen(false)}
                    />
                    <AdminMenu user={user} />
                </SheetContent>
            </Sheet>

            <CommandPalette
                open={paletteOpen}
                onOpenChange={setPaletteOpen}
                navigation={navigation}
            />
        </div>
    );
}

function BrandMark() {
    return (
        <div
            className="flex items-center gap-[11px] border-b px-[18px] py-[16px]"
            style={{ borderColor: 'var(--ck-border)' }}
        >
            <div
                aria-hidden="true"
                className="grid size-8 flex-none grid-cols-3 grid-rows-3 gap-[2.5px] rounded-[8px] p-[6px]"
                style={{ backgroundColor: 'var(--ck-accent)' }}
            >
                {[0.95, 0.2, 0.95, 0.2, 0.95, 0.2, 0.95, 0.2, 0.95].map(
                    (opacity, index) => (
                        <span
                            key={index}
                            className="rounded-[1px]"
                            style={{
                                backgroundColor: 'var(--ck-accent-fg)',
                                opacity,
                            }}
                        />
                    ),
                )}
            </div>
            <div className="text-[16px] leading-none font-extrabold tracking-[-0.01em]">
                Craft
                <span
                    className="font-medium"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    Keeper
                </span>
            </div>
        </div>
    );
}

function AdminMenu({ user }: { user: AppShellUser }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="flex items-center gap-[10px] border-t px-3 py-3 text-left"
                    style={{ borderColor: 'var(--ck-border)' }}
                >
                    <span
                        aria-hidden="true"
                        className="flex size-[30px] flex-none items-center justify-center rounded-[7px] border text-[12px] font-bold"
                        style={{
                            backgroundColor: 'var(--ck-surface-2)',
                            borderColor: 'var(--ck-border-strong)',
                            color: 'var(--ck-text-2)',
                        }}
                    >
                        {user.name.slice(0, 1).toUpperCase()}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-[12px] font-semibold">
                            {user.name}
                        </span>
                        <span
                            className="block truncate font-mono text-[10px]"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            {user.totpEnabled ? 'TOTP on' : 'TOTP off'}
                        </span>
                    </span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" side="top" className="w-56">
                <DropdownMenuLabel>{user.name}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() => router.visit('/design-system')}
                >
                    Design system
                </DropdownMenuItem>
                <DropdownMenuItem disabled>
                    Sign out (available once sign-in ships)
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
