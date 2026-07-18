import type { CSSProperties } from 'react';

/**
 * Shared helpers for the CraftKeeper design-token layer (`--ck-*` custom
 * properties, see `Design/handoff/design-tokens.json`). Centralizes the
 * "badge fills ~15%, badge borders ~35%" color-mix formula documented in
 * `design-tokens.json`'s `$meta.conventions.colorSpaceNotes` so every
 * status/provenance/risk chip derives its tint the same way instead of
 * re-deriving it per component.
 */

/** Semantic tones a chip/glyph can be painted in. `neutral` uses the
 * neutral-surface palette instead of a semantic color (used for
 * "unknown" / "scheduled" / informational states that are not yet
 * success/warning/danger/info). */
export type CkTone =
    'success' | 'warning' | 'danger' | 'info' | 'accent' | 'neutral';

const TONE_VAR: Record<Exclude<CkTone, 'neutral'>, string> = {
    success: '--ck-success',
    warning: '--ck-warning',
    danger: '--ck-danger',
    info: '--ck-info',
    accent: '--ck-accent',
};

/** The solid (100%) color for a tone — used for glyph fills/borders. */
export function ckToneColor(tone: CkTone): string {
    if (tone === 'neutral') {
        return 'var(--ck-text-3)';
    }

    return `var(${TONE_VAR[tone]})`;
}

/** Background/text/border style for a tinted chip (badge). The handoff's
 * `design-tokens.json` documents this as a "badge fills ~15%, badge
 * borders ~35%" convention, but Task 20's computed (not assumed — see
 * that task's docs/architecture/decisions.md entry) contrast audit found
 * the 15% fill fails WCAG 2.2 AA (4.5:1) for solid-tone text on top of
 * it in multiple tone/theme combinations — most notably the "danger"
 * tone StatusBadge (StatusBadge.tsx), which measured ~4.3:1 on
 * `--ck-surface` in dark theme and ~4.05:1 in light theme. A fill tint
 * only ever REDUCES contrast versus the tone color alone (it moves the
 * background toward the text's own color), so the fix is a lower fill
 * percentage: 5% clears 4.5:1 for every StatusBadge tone actually used
 * (success/warning/danger/info) against both `--ck-surface` and
 * `--ck-elevated`, in both themes — every other tone had more margin
 * than danger did at 15%, so this is a strict improvement across the
 * board, not a trade-off against some other tone. */
export function ckChipStyle(tone: CkTone): CSSProperties {
    if (tone === 'neutral') {
        return {
            backgroundColor: 'var(--ck-surface-2)',
            color: 'var(--ck-text-2)',
            borderColor: 'var(--ck-border-strong)',
        };
    }

    const varName = TONE_VAR[tone];

    return {
        backgroundColor: `color-mix(in srgb, var(${varName}) 5%, transparent)`,
        color: `var(${varName})`,
        borderColor: `color-mix(in srgb, var(${varName}) 35%, transparent)`,
    };
}

/** Subtle (~8-10%) surface tint, for banners/notices — see
 * `colorSpaceNotes`: "subtle surfaces ~8-10%". */
export function ckSubtleSurfaceStyle(tone: CkTone): CSSProperties {
    if (tone === 'neutral') {
        return {
            backgroundColor: 'var(--ck-surface)',
            borderColor: 'var(--ck-border)',
        };
    }

    const varName = TONE_VAR[tone];

    return {
        backgroundColor: `color-mix(in srgb, var(${varName}) 9%, var(--ck-surface))`,
        borderColor: `color-mix(in srgb, var(${varName}) 26%, transparent)`,
    };
}
