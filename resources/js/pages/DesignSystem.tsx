import { Head } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PageState } from '@/components/craftkeeper/PageState';
import type { PageStateKind } from '@/components/craftkeeper/PageState';
import { ProvenanceBadge } from '@/components/craftkeeper/ProvenanceBadge';
import type { ProvenanceSource } from '@/components/craftkeeper/ProvenanceBadge';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import {
    STATUS_BADGE_META,
    StatusBadge,
} from '@/components/craftkeeper/StatusBadge';
import type { StatusBadgeStatus } from '@/components/craftkeeper/StatusBadge';
import { CK_ACCENTS, useCkTheme } from '@/hooks/use-ck-theme';
import type { CkAccentName } from '@/hooks/use-ck-theme';
import { AppShell } from '@/layouts/AppShell';

const NEUTRAL_TOKENS = [
    ['bg', '--ck-bg'],
    ['surface', '--ck-surface'],
    ['surface-2', '--ck-surface-2'],
    ['elevated', '--ck-elevated'],
    ['border', '--ck-border'],
    ['border-strong', '--ck-border-strong'],
] as const;

const TEXT_TOKENS = [
    ['text', '--ck-text', '15.8:1'],
    ['text-2', '--ck-text-2', '7.2:1'],
    // Task 20: was documented as "4.6:1" (measured against --ck-bg), but
    // that claim was itself never actually verified — computed by hand
    // it was only ~4.39:1 against --ck-bg and dropped as low as ~3.5:1
    // against --ck-surface/--ck-elevated (below the 4.5:1 AA floor for
    // normal text). The token itself was fixed at the source
    // (resources/css/app.css) rather than patched around here; "4.5:1"
    // below is the new worst case across every surface this token is
    // used on (--ck-elevated, dark theme).
    ['text-3', '--ck-text-3', '4.5:1'],
] as const;

const SEMANTIC_TOKENS = [
    ['success', '--ck-success', 'Saved-and-active, healthy, completed, OK'],
    [
        'warning',
        '--ck-warning',
        'Pending-restart, degraded, needs-review, stale',
    ],
    ['danger', '--ck-danger', 'Failed, offline, destructive, rolled-back'],
    [
        'info',
        '--ck-info',
        'In-progress, scheduled, informational, pending-reload',
    ],
] as const;

const PROVENANCE_TOKENS = [
    ['mounted-server', '--ck-provenance-mounted-server'],
    ['catalog', '--ck-provenance-catalog'],
    ['hangar', '--ck-provenance-hangar'],
    ['modrinth', '--ck-provenance-modrinth'],
    ['documentation', '--ck-provenance-documentation'],
    ['ai-provider', '--ck-provenance-ai-provider'],
    ['administrator', '--ck-provenance-administrator'],
    ['api-mcp', '--ck-provenance-api-mcp'],
] as const;

const DATAVIZ_TOKENS = [
    '--ck-dataviz-1',
    '--ck-dataviz-2',
    '--ck-dataviz-3',
    '--ck-dataviz-4',
    '--ck-dataviz-5',
    '--ck-dataviz-6',
] as const;

const TYPE_SCALE = [
    'display',
    'page-title',
    'section-title',
    'card-heading',
    'body',
    'label',
    'eyebrow',
    'code',
    'micro',
] as const;

const SPACE_SCALE = ['xs', 'sm', 'md', 'lg', 'xl', '2xl'] as const;
const RADIUS_SCALE = [
    'sm',
    'input',
    'button',
    'badge',
    'card',
    'modal',
    'pill',
] as const;

const ALL_STATUSES = Object.keys(STATUS_BADGE_META) as StatusBadgeStatus[];

const ALL_PROVENANCE: ProvenanceSource[] = [
    'built-in',
    'plugin',
    'discovered',
    'catalog',
    'hangar',
    'modrinth',
    'manual',
];

const PAGE_STATE_SAMPLES: PageStateKind[] = [
    'loading',
    'empty',
    'partial-data',
    'offline-server',
    'permission-denied',
    'invalid-config',
    'operation-running',
    'operation-failed',
    'pending-restart',
    'ai-unavailable',
    'marketplace-unavailable',
    'catalog-stale',
];

function SectionHeading({
    eyebrow,
    title,
    description,
}: {
    eyebrow: string;
    title: string;
    description?: string;
}) {
    return (
        <div className="mb-[22px]">
            <div
                className="font-mono text-[11px] font-semibold tracking-[0.12em] uppercase"
                // Task 20 fix pass: was `--ck-accent` directly on this
                // page's ambient `--ck-bg` (every <section> here is bare,
                // uncarded) — 4.17:1 in light theme, under the 4.5:1 AA
                // floor (axe never scanned this page in light theme
                // until Task 20's fix pass added that scan, which is
                // exactly how this went unnoticed). `--ck-text-2` clears
                // AA against `--ck-bg` in both themes (5.98:1 light,
                // 7.99:1 dark) and this is the only place this
                // page-local SectionHeading draws its eyebrow color from.
                style={{ color: 'var(--ck-text-2)' }}
            >
                {eyebrow}
            </div>
            <h2
                className="mt-[9px] text-[24px] leading-[1.2] font-bold"
                style={{ color: 'var(--ck-text)' }}
            >
                {title}
            </h2>
            {description && (
                <p
                    className="mt-[6px] max-w-[640px] text-[14px] leading-[1.55]"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    {description}
                </p>
            )}
        </div>
    );
}

function ColorSwatch({ name, varName }: { name: string; varName: string }) {
    // The swatch's *background* is the token being demonstrated, so it
    // ranges from near-black neutrals to a saturated accent hue — a
    // single fixed foreground can't hold AA contrast across all of them.
    // `--ck-accent-fg` is the token designed to sit on `--ck-accent`;
    // `--ck-text` (the highest-contrast neutral) covers every other,
    // much darker swatch.
    const foreground =
        name === 'accent' ? 'var(--ck-accent-fg)' : 'var(--ck-text)';

    return (
        <div
            className="flex min-h-[72px] flex-col justify-end rounded-[8px] border p-[12px]"
            style={{
                backgroundColor: `var(${varName})`,
                borderColor: 'var(--ck-border)',
            }}
        >
            <div
                className="text-[12px] font-semibold"
                style={{ color: foreground }}
            >
                {name}
            </div>
            <div
                className="font-mono text-[10px]"
                style={{ color: foreground }}
            >
                {varName}
            </div>
        </div>
    );
}

function Card({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={`rounded-[9px] border p-[18px] ${className ?? ''}`}
            style={{
                backgroundColor: 'var(--ck-surface)',
                borderColor: 'var(--ck-border)',
                boxShadow: 'var(--ck-shadow-e1)',
            }}
        >
            {children}
        </div>
    );
}

function AccentPicker() {
    const { accent, setAccent } = useCkTheme();

    return (
        <div
            className="flex items-center gap-[6px] rounded-[8px] border p-[4px]"
            style={{
                backgroundColor: 'var(--ck-surface)',
                borderColor: 'var(--ck-border)',
            }}
        >
            {CK_ACCENTS.map((option) => (
                <button
                    key={option}
                    type="button"
                    aria-label={`${option} accent`}
                    aria-pressed={accent === option}
                    onClick={() => setAccent(option)}
                    className="size-[22px] rounded-[6px]"
                    style={{
                        backgroundColor: ACCENT_SWATCH[option],
                        outline:
                            accent === option
                                ? '2px solid var(--ck-text)'
                                : '2px solid transparent',
                        outlineOffset: 2,
                    }}
                />
            ))}
        </div>
    );
}

const ACCENT_SWATCH: Record<CkAccentName, string> = {
    terracotta: '#cf6c40',
    emerald: '#4fa87a',
    slate: '#5f97cf',
    bronze: '#bd9a54',
};

function DesignSystemContent() {
    const { theme, setTheme } = useCkTheme();
    const [pageStateIndex, setPageStateIndex] = useState(0);
    const activePageState = PAGE_STATE_SAMPLES[pageStateIndex];

    return (
        <div className="grid gap-[40px] pb-[60px]">
            <section>
                <div className="flex flex-wrap items-end justify-between gap-[16px]">
                    <div>
                        <div
                            className="font-mono text-[11px] font-semibold tracking-[0.12em] uppercase"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Foundation · design kit
                        </div>
                        <h1
                            className="mt-[9px] text-[28px] leading-[1.1] font-extrabold tracking-[-0.02em]"
                            style={{ color: 'var(--ck-text)' }}
                        >
                            Design system
                        </h1>
                        <p
                            className="mt-[10px] max-w-[640px] text-[14px] leading-[1.6]"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            The palette, type, tokens, and shared primitives
                            CraftKeeper is built from. Every element re-themes
                            live — switch theme and accent below.
                        </p>
                    </div>
                    <div className="flex items-center gap-[10px]">
                        <div
                            className="flex items-center gap-1 rounded-[7px] border p-1"
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
                                    className="rounded-[5px] px-[12px] py-[5px] text-[12px] font-semibold capitalize"
                                    style={
                                        theme === mode
                                            ? {
                                                  backgroundColor:
                                                      'var(--ck-accent)',
                                                  color: 'var(--ck-accent-fg)',
                                              }
                                            : { color: 'var(--ck-text-2)' }
                                    }
                                >
                                    {mode}
                                </button>
                            ))}
                        </div>
                        <AccentPicker />
                    </div>
                </div>
            </section>

            <section>
                <SectionHeading
                    eyebrow="01 — Color"
                    title="Color tokens"
                    description="Warm, papery neutrals carry the interface; color is reserved for state and provenance. Every value below is a live --ck-* custom property."
                />
                <div className="grid gap-[22px] lg:grid-cols-2">
                    <div className="grid gap-[10px]">
                        <div
                            className="text-[12px] font-semibold"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Surfaces & neutrals
                        </div>
                        <div className="grid grid-cols-2 gap-[6px] sm:grid-cols-3">
                            {NEUTRAL_TOKENS.map(([name, varName]) => (
                                <ColorSwatch
                                    key={name}
                                    name={name}
                                    varName={varName}
                                />
                            ))}
                            <ColorSwatch name="accent" varName="--ck-accent" />
                        </div>
                        <Card className="grid gap-[10px]">
                            {/* Task 20: text-3 previously failed AA
                                against this card's --ck-surface (~3.5-
                                4.1:1), so its row rendered as --ck-text-2
                                plus a separate color swatch instead of
                                real same-colored text. The token was
                                fixed at the source (resources/css/
                                app.css) — text-3 now clears 4.5:1 against
                                every surface it is used on, so every row
                                below (including this one) renders in its
                                own real color like the other two. */}
                            {TEXT_TOKENS.map(([name, varName, ratio]) => (
                                <div
                                    key={name}
                                    className="flex items-center gap-[10px]"
                                >
                                    <span
                                        className="text-[14px] font-semibold"
                                        style={{ color: `var(${varName})` }}
                                    >
                                        {name} — {ratio}
                                    </span>{' '}
                                    <code
                                        className="font-mono text-[11px]"
                                        style={{ color: 'var(--ck-text-2)' }}
                                    >
                                        {varName}
                                    </code>
                                </div>
                            ))}
                        </Card>
                    </div>

                    <div className="grid gap-[10px]">
                        <div
                            className="text-[12px] font-semibold"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Semantic states
                        </div>
                        <div className="grid gap-[8px]">
                            {SEMANTIC_TOKENS.map(([name, varName, usage]) => (
                                <div
                                    key={name}
                                    className="flex items-center gap-[14px] rounded-[8px] border p-[13px]"
                                    style={{
                                        backgroundColor: 'var(--ck-surface)',
                                        borderColor: 'var(--ck-border)',
                                    }}
                                >
                                    <span
                                        aria-hidden="true"
                                        className="size-[26px] flex-none rounded-[6px]"
                                        style={{
                                            backgroundColor: `var(${varName})`,
                                        }}
                                    />
                                    <div className="flex-1">
                                        <div className="text-[13px] font-semibold">
                                            {name}
                                        </div>
                                        <div
                                            className="text-[12px]"
                                            style={{
                                                color: 'var(--ck-text-2)',
                                            }}
                                        >
                                            {usage}
                                        </div>
                                    </div>
                                    <code
                                        className="font-mono text-[11px]"
                                        style={{ color: 'var(--ck-text-2)' }}
                                    >
                                        {varName}
                                    </code>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="mt-[26px] grid gap-[22px] lg:grid-cols-2">
                    <div>
                        <div
                            className="mb-[10px] text-[12px] font-semibold"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Provenance — where information &amp; actions came
                            from
                        </div>
                        <div className="flex flex-wrap gap-[9px]">
                            {PROVENANCE_TOKENS.map(([name, varName]) => (
                                <span
                                    key={name}
                                    className="inline-flex items-center gap-[6px] rounded-[5px] border px-[10px] py-[5px] font-sans text-[11.5px] font-semibold"
                                    style={{
                                        backgroundColor: 'var(--ck-surface-2)',
                                        color: 'var(--ck-text-2)',
                                        borderColor: 'var(--ck-border-strong)',
                                    }}
                                >
                                    <span
                                        aria-hidden="true"
                                        style={{
                                            width: 7,
                                            height: 7,
                                            borderRadius: 2,
                                            backgroundColor: `var(${varName})`,
                                        }}
                                    />
                                    {name}
                                </span>
                            ))}
                        </div>
                    </div>
                    <div>
                        <div
                            className="mb-[10px] text-[12px] font-semibold"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Data-visualization palette
                        </div>
                        <div
                            className="flex items-end gap-[6px] rounded-[8px] border p-[16px]"
                            style={{
                                backgroundColor: 'var(--ck-surface)',
                                borderColor: 'var(--ck-border)',
                                height: 96,
                            }}
                        >
                            {DATAVIZ_TOKENS.map((varName, index) => (
                                <div
                                    key={varName}
                                    aria-hidden="true"
                                    className="flex-1 rounded-t-[3px]"
                                    style={{
                                        height: `${40 + index * 10}%`,
                                        backgroundColor: `var(${varName})`,
                                    }}
                                />
                            ))}
                        </div>
                        <p
                            className="mt-[8px] text-[11px] leading-[1.5]"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Six harmonious hues for TPS, memory, players. Charts
                            always ship a textual equivalent.
                        </p>
                    </div>
                </div>
            </section>

            <section>
                <SectionHeading
                    eyebrow="02 — Type"
                    title="Typography"
                    description="Hanken Grotesk for all UI text, JetBrains Mono for paths, keys, code, console, diffs. Self-hosted, font-display: swap."
                />
                <Card className="grid gap-[14px]">
                    {TYPE_SCALE.map((name) => (
                        <div
                            key={name}
                            className="flex items-baseline justify-between gap-[16px] border-b pb-[12px] last:border-b-0 last:pb-0"
                            style={{ borderColor: 'var(--ck-border)' }}
                        >
                            <span
                                style={{
                                    fontFamily: `var(--ck-text-${name}-family)`,
                                    fontSize: `var(--ck-text-${name}-size)`,
                                    fontWeight:
                                        `var(--ck-text-${name}-weight)` as unknown as number,
                                    color: 'var(--ck-text)',
                                }}
                            >
                                {name.replace('-', ' ')}
                            </span>
                            <code
                                className="font-mono text-[11px]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                --ck-text-{name}-size
                            </code>
                        </div>
                    ))}
                </Card>
            </section>

            <section>
                <SectionHeading
                    eyebrow="03 — Spacing, radius & elevation"
                    title="Layout tokens"
                />
                <div className="grid gap-[22px] lg:grid-cols-3">
                    <Card>
                        <div
                            className="mb-[10px] text-[12px] font-semibold"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Spacing
                        </div>
                        <div className="grid gap-[6px]">
                            {SPACE_SCALE.map((name) => (
                                <div
                                    key={name}
                                    className="flex items-center gap-[10px]"
                                >
                                    <span
                                        aria-hidden="true"
                                        style={{
                                            width: `var(--ck-space-${name})`,
                                            height: 10,
                                            backgroundColor: 'var(--ck-accent)',
                                            borderRadius: 2,
                                        }}
                                    />
                                    <code
                                        className="font-mono text-[11px]"
                                        style={{ color: 'var(--ck-text-2)' }}
                                    >
                                        --ck-space-{name}
                                    </code>
                                </div>
                            ))}
                        </div>
                    </Card>
                    <Card>
                        <div
                            className="mb-[10px] text-[12px] font-semibold"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Radius
                        </div>
                        <div className="grid grid-cols-2 gap-[10px]">
                            {RADIUS_SCALE.map((name) => (
                                <div
                                    key={name}
                                    className="flex items-center gap-[8px]"
                                >
                                    <span
                                        aria-hidden="true"
                                        style={{
                                            width: 22,
                                            height: 22,
                                            backgroundColor:
                                                'var(--ck-surface-2)',
                                            border: '1px solid var(--ck-border-strong)',
                                            borderRadius: `var(--ck-radius-${name})`,
                                        }}
                                    />
                                    <code
                                        className="font-mono text-[11px]"
                                        style={{ color: 'var(--ck-text-2)' }}
                                    >
                                        {name}
                                    </code>
                                </div>
                            ))}
                        </div>
                    </Card>
                    <Card>
                        <div
                            className="mb-[10px] text-[12px] font-semibold"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            Elevation
                        </div>
                        <div className="grid gap-[14px]">
                            {(['e0', 'e1', 'e2'] as const).map((level) => (
                                <div
                                    key={level}
                                    className="flex h-[40px] items-center justify-center rounded-[8px] border font-mono text-[11px]"
                                    style={{
                                        backgroundColor: 'var(--ck-elevated)',
                                        borderColor: 'var(--ck-border)',
                                        boxShadow: `var(--ck-shadow-${level})`,
                                        // Task 20: previously forced to
                                        // --ck-text-2 because --ck-text-3
                                        // fell to ~3.5:1 against
                                        // --ck-elevated (below AA). The
                                        // token was fixed at the source
                                        // (resources/css/app.css) — it now
                                        // clears 4.5:1 here too, so this
                                        // label uses its originally
                                        // intended tertiary color again.
                                        color: 'var(--ck-text-3)',
                                    }}
                                >
                                    --ck-shadow-{level}
                                </div>
                            ))}
                        </div>
                    </Card>
                </div>
            </section>

            <section>
                <SectionHeading
                    eyebrow="04 — Components"
                    title="Status, badges & risk"
                    description={
                        'Operational state is honest and specific — "saved but pending restart" is never collapsed into "saved". Every status combines color, a distinct shape glyph, and a label.'
                    }
                />
                <div
                    className="mb-[10px] text-[12px] font-semibold"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    StatusBadge — every documented state
                </div>
                {/* Task 20 fix pass: this used to render bare on this
                    page's ambient --ck-bg — not one of the two surfaces
                    (`--ck-surface`, `--ck-elevated`) StatusBadge's chip
                    fill is actually contrast-verified against (see
                    ck-tokens.ts's ckChipStyle docblock), and not how any
                    real page uses it either (Overview/Integrations/
                    server cards all wrap it in a --ck-surface card).
                    Wrapping this demo the same way both fixes a real
                    light-theme AA failure (success/warning/danger chips
                    at 3.9-4.5:1 against bare --ck-bg) and makes the
                    gallery honest about the context these chips are
                    actually designed for. */}
                <div
                    className="flex flex-wrap gap-[9px] rounded-[9px] border p-[14px]"
                    style={{
                        backgroundColor: 'var(--ck-surface)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    {ALL_STATUSES.map((status) => (
                        <StatusBadge key={status} status={status} />
                    ))}
                </div>

                <div
                    className="mt-[24px] mb-[10px] text-[12px] font-semibold"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    ProvenanceBadge — every documented source
                </div>
                <div className="flex flex-wrap gap-[9px]">
                    {ALL_PROVENANCE.map((source) => (
                        <ProvenanceBadge key={source} source={source} />
                    ))}
                </div>

                <div
                    className="mt-[24px] mb-[10px] text-[12px] font-semibold"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    RestartRequired
                </div>
                {/* Task 20 fix pass: matches the chip's other real card
                    context (plugins/Upload.tsx wraps its RestartRequired
                    chip in --ck-elevated). AppShell.tsx's header ALSO
                    renders this exact chip bare on --ck-bg (no card) —
                    that's a real, intentional usage too, which is why
                    every light-theme tone was darkened enough to clear
                    --ck-bg as well as --ck-surface/--ck-elevated (see
                    ck-tokens.ts's ckChipStyle docblock) rather than
                    "fixing" it by insisting a card wrapper is always
                    required. The banner variant already draws its own
                    surface via ckSubtleSurfaceStyle, so only the chip
                    needed a wrapper here. */}
                <div
                    className="flex flex-wrap items-start gap-[14px] rounded-[9px] border p-[14px]"
                    style={{
                        backgroundColor: 'var(--ck-surface)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <RestartRequired variant="chip" />
                    <RestartRequired
                        variant="banner"
                        className="max-w-[380px]"
                    />
                </div>

                {/* Task 20 fix pass: a dedicated fixture for
                    tests/e2e/design-system.spec.ts's chip-contrast guard.
                    Real StatusBadge/RestartRequired components (not a
                    hand-rolled re-implementation of ckChipStyle's color
                    math) rendered on all three real backgrounds a chip
                    actually sits on in this app (--ck-surface, e.g.
                    Overview/Integrations/server cards; --ck-elevated,
                    e.g. plugins/Upload.tsx's RestartRequired chip;
                    --ck-bg, e.g. AppShell.tsx's header RestartRequired
                    chip) — the test reads getComputedStyle() off these
                    live elements so a regression in the token/fill math
                    fails it. One representative status per tone: online
                    (success), degraded (warning), offline (danger),
                    in-progress (info). */}
                <div
                    className="mt-[24px] mb-[10px] text-[12px] font-semibold"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Chip contrast fixture — every tone on all three real
                    chip surfaces
                </div>
                <div className="grid gap-[14px] sm:grid-cols-3">
                    <div
                        data-test="chip-contrast-bg"
                        className="flex flex-wrap items-start gap-[9px] rounded-[9px] border p-[14px]"
                        style={{
                            backgroundColor: 'var(--ck-bg)',
                            borderColor: 'var(--ck-border)',
                        }}
                    >
                        <StatusBadge status="online" />
                        <StatusBadge status="degraded" />
                        <StatusBadge status="offline" />
                        <StatusBadge status="in-progress" />
                        <RestartRequired variant="chip" />
                    </div>
                    <div
                        data-test="chip-contrast-surface"
                        className="flex flex-wrap items-start gap-[9px] rounded-[9px] border p-[14px]"
                        style={{
                            backgroundColor: 'var(--ck-surface)',
                            borderColor: 'var(--ck-border)',
                        }}
                    >
                        <StatusBadge status="online" />
                        <StatusBadge status="degraded" />
                        <StatusBadge status="offline" />
                        <StatusBadge status="in-progress" />
                        <RestartRequired variant="chip" />
                    </div>
                    <div
                        data-test="chip-contrast-elevated"
                        className="flex flex-wrap items-start gap-[9px] rounded-[9px] border p-[14px]"
                        style={{
                            backgroundColor: 'var(--ck-elevated)',
                            borderColor: 'var(--ck-border)',
                        }}
                    >
                        <StatusBadge status="online" />
                        <StatusBadge status="degraded" />
                        <StatusBadge status="offline" />
                        <StatusBadge status="in-progress" />
                        <RestartRequired variant="chip" />
                    </div>
                </div>
            </section>

            <section>
                <SectionHeading
                    eyebrow="05 — Components"
                    title="Page states"
                    description="Unavailable telemetry is labeled honestly and never simulated. Cycle through the documented PageState kinds below."
                />
                <div className="mb-[14px] flex flex-wrap gap-[8px]">
                    {PAGE_STATE_SAMPLES.map((state, index) => (
                        <button
                            key={state}
                            type="button"
                            aria-pressed={index === pageStateIndex}
                            onClick={() => setPageStateIndex(index)}
                            className="rounded-[6px] border px-[10px] py-[6px] font-mono text-[11px]"
                            style={
                                index === pageStateIndex
                                    ? {
                                          backgroundColor: 'var(--ck-accent)',
                                          color: 'var(--ck-accent-fg)',
                                          borderColor: 'var(--ck-accent)',
                                      }
                                    : {
                                          color: 'var(--ck-text-2)',
                                          borderColor:
                                              'var(--ck-border-strong)',
                                      }
                            }
                        >
                            {state}
                        </button>
                    ))}
                </div>
                <Card>
                    <PageState state={activePageState} />
                </Card>
            </section>

            <section>
                <SectionHeading
                    eyebrow="06 — Shell"
                    title="Application shell"
                    description="This entire page is rendered inside the shared AppShell — sidebar navigation at 1024px and above, a header + drawer below it, and a command palette reachable from the top bar or ⌘K / Ctrl+K."
                />
                <Card>
                    <p
                        className="text-[13px] leading-[1.6]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Press{' '}
                        <kbd
                            className="rounded-[4px] border px-[6px] py-[2px] font-mono text-[11px]"
                            style={{
                                backgroundColor: 'var(--ck-surface-2)',
                                borderColor: 'var(--ck-border-strong)',
                            }}
                        >
                            ⌘K
                        </kbd>{' '}
                        (or{' '}
                        <kbd
                            className="rounded-[4px] border px-[6px] py-[2px] font-mono text-[11px]"
                            style={{
                                backgroundColor: 'var(--ck-surface-2)',
                                borderColor: 'var(--ck-border-strong)',
                            }}
                        >
                            Ctrl K
                        </kbd>
                        ) anywhere on this page to open the command palette.
                        Resize the window below 1024px to see the sidebar
                        collapse into a header and navigation drawer.
                    </p>
                </Card>
            </section>
        </div>
    );
}

export default function DesignSystem() {
    return (
        <>
            <Head title="Design system" />
            <AppShell pendingRestart>
                <DesignSystemContent />
            </AppShell>
        </>
    );
}
