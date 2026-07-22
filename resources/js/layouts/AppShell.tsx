import { Link, router, usePage } from '@inertiajs/react';
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
import { logout } from '@/routes';

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

/**
 * Everything here is nullable, and null means UNKNOWN.
 *
 * This file used to carry a DEFAULT_SERVER of "Survival" / "mc.example.net"
 * / "Paper 1.21.4" / status online / 3 of 40 players, and a DEFAULT_USER of
 * "admin" with TOTP on — a design-system mock that became the production
 * default because not one of the 25 `<AppShell>` call sites ever passed
 * anything. Every install rendered all of it, on every page. The real
 * values now arrive as an Inertia shared prop (App\Http\Middleware\
 * HandleInertiaRequests::shell), which no page can forget to pass.
 *
 * No field falls back to a plausible-looking value. Minecraft's own default
 * max-players is 20, and using it would be indistinguishable to an operator
 * from having actually read their server.properties.
 */
export interface AppShellServerIdentity {
    name: string | null;
    version: string | null;
    status: StatusBadgeStatus;
    playersOnline: number | null;
    playersMax: number | null;
    /** Why the player count is unknown, when it is — surfaced as a title
     * so the absence is explained rather than merely blank. */
    playersReason?: string | null;
}

export interface AppShellUser {
    name: string;
    totpEnabled: boolean;
}

/** Shape of the `shell` shared prop. Absent for guests. */
interface ShellSharedProps {
    shell?: {
        server: AppShellServerIdentity;
        user: AppShellUser;
    } | null;
    /** Inertia's own `PageProps` constraint — other shared props exist
     * (auth, name, sidebarOpen); this type only narrows the one read here. */
    [key: string]: unknown;
}

/** Rendered when CraftKeeper genuinely does not know a value. */
const UNKNOWN_SERVER: AppShellServerIdentity = {
    name: null,
    version: null,
    status: 'unknown',
    playersOnline: null,
    playersMax: null,
    playersReason: null,
};

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
                                      // --ck-text, not --ck-accent-hover:
                                      // Task 12's e2e axe scan (the first
                                      // one to actually reach a desktop
                                      // view with an active primary-nav
                                      // item — configuration.spec.ts and
                                      // design-system.spec.ts only ever
                                      // axe-scan the mobile drawer or a
                                      // non-nav-item route) found
                                      // --ck-accent-hover text on this
                                      // ~16%-tint background measures
                                      // 4.45:1, under the 4.5:1 AA
                                      // threshold. --ck-text holds
                                      // ~10.7:1 on the same background —
                                      // the same class of fix already
                                      // documented for DiffReview/
                                      // ServerIdentityCard in
                                      // docs/architecture/decisions.md.
                                      color: 'var(--ck-text)',
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
                    {server.name ?? 'Minecraft server'}
                </span>
                {/* Status must never rely on color alone: pair the same
                    per-status shape glyph StatusBadge uses elsewhere
                    (square-pulse for online, triangle for
                    pending-restart, etc.) with a visible text label,
                    rather than color alone. This reuses StatusGlyph
                    directly rather than the full StatusBadge chip purely
                    for layout density in this compact identity-card row
                    — as of Task 20's fix pass the chip itself clears AA
                    on both --ck-surface and --ck-elevated (see
                    ck-tokens.ts's ckChipStyle docblock), so this is not
                    an AA workaround. --ck-text-2 is the token used for
                    the label here and below. */}
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
                {/* No address line: CraftKeeper has no way to know the
                    hostname players connect on — it manages a filesystem
                    and an RCON port, neither of which is the public
                    address. The old mock printed "mc.example.net" here. */}
                {server.version ?? 'Version unknown'}
            </div>
            <div
                className="mt-[9px] flex items-center gap-[6px] text-[11px] font-semibold"
                style={{ color: 'var(--ck-text-2)' }}
                data-test="shell-player-count"
                title={
                    server.playersOnline === null
                        ? (server.playersReason ?? undefined)
                        : undefined
                }
            >
                <span
                    aria-hidden="true"
                    style={{
                        width: 6,
                        height: 6,
                        borderRadius: 1,
                        // Follows the real state. The old mock painted this
                        // --ck-success unconditionally, so a server nobody
                        // could reach still showed a green dot.
                        backgroundColor:
                            server.playersOnline === null
                                ? 'var(--ck-text-3)'
                                : 'var(--ck-success)',
                    }}
                />
                {server.playersOnline === null
                    ? 'Players unknown'
                    : server.playersMax === null
                      ? /* max-players is only knowable from
                           server.properties; when that is unreadable the
                           count still is, so show it alone rather than
                           inventing a denominator. */
                        `${server.playersOnline} online`
                      : `${server.playersOnline} / ${server.playersMax} online`}
            </div>
        </div>
    );
}

function AppShellChrome({
    children,
    navigation = DEFAULT_NAVIGATION,
    server: serverProp,
    user: userProp,
    pendingRestart = false,
}: AppShellProps) {
    // Real values come from the shared prop, so no page can render the
    // shell without them. The explicit props remain for the one legitimate
    // use — resources/js/pages/DesignSystem.tsx showing the component with
    // sample data — which is where a mock belongs.
    const { shell } = usePage<ShellSharedProps>().props;
    const server = serverProp ?? shell?.server ?? UNKNOWN_SERVER;
    const user = userProp ?? shell?.user ?? null;

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
                            // Task 20 fix pass: was a 14% `--ck-accent`
                            // tint — same anti-pattern ck-tokens.ts's
                            // ckChipStyle docblock documents at length: a
                            // fill tint only ever REDUCES contrast versus
                            // same-hued text, and this button's own
                            // `--ck-accent-hover` label text measured
                            // only 4.24:1 against the tinted effective
                            // background in light theme (under 4.5:1).
                            // Dropping the fill to 0% (transparent, relying
                            // on the border) fixes it: the SAME label text
                            // against the real, untinted background behind
                            // this header clears 5.04:1.
                            backgroundColor: 'transparent',
                            color: 'var(--ck-accent-hover)',
                        }}
                    >
                        <span
                            aria-hidden="true"
                            className="flex size-4 items-center justify-center rounded-[4px] text-[8px] font-bold"
                            style={{
                                // Task 20 fix pass: `--ck-provenance-ai-
                                // provider` (this purple) is NOT
                                // per-theme (resources/css/app.css defines
                                // it once, same hex in both themes) — the
                                // comment this replaces assumed "a solid
                                // fill with --ck-bg text holds AA," which
                                // only happened to be true because
                                // `--ck-bg` is dark in the dark theme this
                                // was written/checked against; in light
                                // theme `--ck-bg` flips to near-white,
                                // measuring 2.15:1 against this same fixed
                                // purple (a severe failure, only caught
                                // once axe was ever run in light theme).
                                // This glyph needs a FOREGROUND THAT DOES
                                // NOT FLIP WITH THEME, since its
                                // background doesn't either: a fixed dark
                                // ink (the same shade `--ck-accent-fg`/
                                // `--ck-danger-fg` use for dark-theme
                                // text-on-solid-fill) clears 7.09:1 here
                                // in both themes.
                                backgroundColor:
                                    'var(--ck-provenance-ai-provider)',
                                color: '#1c1512',
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

function AdminMenu({ user }: { user: AppShellUser | null }) {
    // Null only when the shared prop is absent, which means no
    // authenticated user — the shell is not rendered for guests, so this
    // is a defensive branch rather than a state an operator reaches. It
    // shows nothing rather than the old "admin / TOTP on" placeholder,
    // which asserted a two-factor state it had never checked.
    if (user === null) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    data-test="shell-account-menu"
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
                {/* Was a DISABLED item reading "Sign out (available once
                    sign-in ships)" — written before authentication existed
                    and never revisited, so the operator's own account menu
                    offered no way out long after sign-in shipped. Uses the
                    same Fortify logout route as
                    resources/js/components/user-menu-content.tsx. */}
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={logout()}
                        as="button"
                        // Drops Inertia's cached page state on the way out,
                        // matching user-menu-content.tsx — otherwise a
                        // subsequent sign-in can be served a page rendered
                        // for the previous session.
                        onClick={() => router.flushAll()}
                        data-test="shell-logout-button"
                    >
                        Sign out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
