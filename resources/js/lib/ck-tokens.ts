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
 * borders ~35%" convention. Task 20's first pass lowered the fill to 5%
 * (a HIGHER tint paradoxically REDUCES contrast versus same-colored
 * text, since it moves the background toward the text's own color) and
 * claimed that cleared 4.5:1 for every tone against both `--ck-surface`
 * and `--ck-elevated`, in both themes — that claim was never actually
 * recomputed per tone/surface/theme and was FALSE. Hand-computed (sRGB
 * -> linear -> relative luminance, not trusted from any handoff doc),
 * the 5% fill measured, worst case per tone:
 *
 *   dark   danger  vs elevated: 4.31:1  (below 4.5:1)
 *   light  success vs surface : 3.68:1  vs elevated: 3.93:1
 *   light  warning vs surface : 4.07:1  vs elevated: 4.34:1
 *
 * A fill tint can only ever REDUCE contrast versus the bare tone color,
 * so no positive fill percentage clears AA for light success/warning —
 * even a 0% fill (no tint at all) only reaches 3.90:1 / 4.32:1 there,
 * because those two LIGHT-theme tone colors themselves aren't dark
 * enough against this theme's near-white surfaces. The fix has two
 * parts:
 *
 *   1. Drop the chip fill to 0% (`background: transparent`) and rely on
 *      the tone-colored border instead — this alone fixes the dark
 *      danger-vs-elevated case (3.70:1 at 15% -> 4.31:1 at 5% -> 4.62:1
 *      at 0%) and every other already-passing tone/theme/surface (more
 *      margin, not less).
 *   2. Darken every LIGHT-theme tone (`resources/css/app.css`) — the 0%
 *      fill alone cannot clear AA for success/warning (the raw token
 *      color itself is too light, not the tint), and danger/info were
 *      only passing `--ck-surface`/`--ck-elevated` by a hair.
 *
 * A THIRD real surface widened this fix mid-review: a chip isn't only
 * ever painted on `--ck-surface`/`--ck-elevated` — `AppShell.tsx`'s
 * header renders a warning-tone `RestartRequired` chip directly on
 * `--ck-bg` app-wide whenever a restart is pending, and `--ck-bg` is
 * slightly DARKER than `--ck-surface` in the light theme, making it the
 * tightest constraint of the three for a dark-on-light chip tone. Every
 * light-theme tone is darkened enough to clear all three.
 *
 * MEASURED result (0% fill, darkened light tones), the floor across
 * every tone x surface (`--ck-bg`/`--ck-surface`/`--ck-elevated`) x
 * theme combination is 4.60:1 — see docs/architecture/decisions.md's
 * Task 20 fix-pass entry for the full per-tone table. */
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
        backgroundColor: 'transparent',
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
