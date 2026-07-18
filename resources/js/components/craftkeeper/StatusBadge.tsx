import type { CSSProperties } from 'react';
import { ckChipStyle, ckToneColor } from '@/lib/ck-tokens';
import type { CkTone } from '@/lib/ck-tokens';
import { cn } from '@/lib/utils';

/**
 * Honest operational state. Per `Design/handoff/components.json`
 * (`StatusBadge`): "never collapse 'pending restart' into 'saved'". Every
 * status is encoded as color + a distinct shape glyph + a text label —
 * color is never the only signal (WCAG 2.2 AA).
 */
type StatusGlyphKind =
    | 'square'
    | 'square-pulse'
    | 'triangle'
    | 'circle'
    | 'ring'
    | 'spinner'
    | 'check'
    | 'cross'
    | 'undo';

type StatusBadgeMetaEntry = {
    /** Canonical, human-readable status name. Used as the accessible name
     * and as the visible label when no context-specific `label` is given. */
    label: string;
    tone: CkTone;
    glyph: StatusGlyphKind;
};

export const STATUS_BADGE_META = {
    online: { label: 'Online', tone: 'success', glyph: 'square-pulse' },
    degraded: { label: 'Degraded', tone: 'warning', glyph: 'square' },
    offline: { label: 'Offline', tone: 'danger', glyph: 'square' },
    unknown: { label: 'Unknown', tone: 'neutral', glyph: 'square' },
    // Task 19's Integrations overview: Connected/Disabled/Degraded/
    // Misconfigured for all ten integrations (App\Support\
    // IntegrationHealthChecker). `degraded` above is reused as-is;
    // these three are additive — every existing STATUS_BADGE_META
    // consumer is unaffected.
    connected: { label: 'Connected', tone: 'success', glyph: 'square-pulse' },
    disabled: { label: 'Disabled', tone: 'neutral', glyph: 'square' },
    misconfigured: { label: 'Misconfigured', tone: 'danger', glyph: 'triangle' },
    'in-progress': { label: 'In progress', tone: 'info', glyph: 'spinner' },
    'pending-restart': {
        label: 'Pending restart',
        tone: 'warning',
        glyph: 'triangle',
    },
    'pending-reload': {
        label: 'Pending reload',
        tone: 'info',
        glyph: 'circle',
    },
    scheduled: { label: 'Scheduled', tone: 'neutral', glyph: 'ring' },
    completed: { label: 'Completed', tone: 'success', glyph: 'check' },
    failed: { label: 'Failed', tone: 'danger', glyph: 'cross' },
    'rolled-back': { label: 'Rolled back', tone: 'danger', glyph: 'undo' },
} as const satisfies Record<string, StatusBadgeMetaEntry>;

export type StatusBadgeStatus = keyof typeof STATUS_BADGE_META;

export interface StatusBadgeProps {
    status: StatusBadgeStatus;
    /** Context-specific visible copy, e.g. "RCON unavailable". Falls back
     * to the status's canonical label (e.g. "Degraded") when omitted. */
    label?: string;
    className?: string;
}

/**
 * Exported so call sites that can't use the full `StatusBadge` chip (e.g.
 * a compact card whose background isn't one of the chip fill's
 * contrast-verified surfaces) can still pair the same per-status shape
 * catalog with their own visible label.
 */
export function StatusGlyph({
    tone,
    glyph,
}: {
    tone: CkTone;
    glyph: StatusGlyphKind;
}) {
    const color = ckToneColor(tone);
    const base: CSSProperties = { flex: 'none' };

    switch (glyph) {
        case 'square-pulse':
            return (
                <span
                    data-ck-glyph="square-pulse"
                    aria-hidden="true"
                    className="ck-pulse"
                    style={{
                        ...base,
                        width: 8,
                        height: 8,
                        borderRadius: 2,
                        backgroundColor: color,
                    }}
                />
            );
        case 'square':
            return (
                <span
                    data-ck-glyph="square"
                    aria-hidden="true"
                    style={{
                        ...base,
                        width: 8,
                        height: 8,
                        borderRadius: 2,
                        backgroundColor: color,
                    }}
                />
            );
        case 'triangle':
            return (
                <span
                    data-ck-glyph="triangle"
                    aria-hidden="true"
                    style={{
                        ...base,
                        width: 0,
                        height: 0,
                        borderLeft: '5px solid transparent',
                        borderRight: '5px solid transparent',
                        borderBottom: `8px solid ${color}`,
                    }}
                />
            );
        case 'circle':
            return (
                <span
                    data-ck-glyph="circle"
                    aria-hidden="true"
                    style={{
                        ...base,
                        width: 8,
                        height: 8,
                        borderRadius: 9999,
                        backgroundColor: color,
                    }}
                />
            );
        case 'ring':
            return (
                <span
                    data-ck-glyph="ring"
                    aria-hidden="true"
                    style={{
                        ...base,
                        width: 8,
                        height: 8,
                        borderRadius: 2,
                        border: `1.5px solid ${color}`,
                    }}
                />
            );
        case 'spinner':
            return (
                <span
                    data-ck-glyph="spinner"
                    aria-hidden="true"
                    className="ck-spin"
                    style={{
                        ...base,
                        width: 8,
                        height: 8,
                        borderRadius: 9999,
                        border: `2px solid ${color}`,
                        borderRightColor: 'transparent',
                    }}
                />
            );
        case 'check':
            return (
                <span
                    data-ck-glyph="check"
                    aria-hidden="true"
                    style={{ ...base, fontWeight: 700, fontSize: 11, color }}
                >
                    ✓
                </span>
            );
        case 'cross':
            return (
                <span
                    data-ck-glyph="cross"
                    aria-hidden="true"
                    style={{ ...base, fontWeight: 700, fontSize: 11, color }}
                >
                    ✕
                </span>
            );
        case 'undo':
            return (
                <span
                    data-ck-glyph="undo"
                    aria-hidden="true"
                    style={{ ...base, fontWeight: 700, fontSize: 11, color }}
                >
                    ↺
                </span>
            );
    }
}

export function StatusBadge({ status, label, className }: StatusBadgeProps) {
    const meta = STATUS_BADGE_META[status];
    const visibleLabel = label ?? meta.label;
    const accessibleName =
        label && label !== meta.label ? `${meta.label}: ${label}` : meta.label;

    return (
        <span
            role="status"
            aria-label={accessibleName}
            data-ck-status={status}
            className={cn(
                'inline-flex items-center gap-[7px] rounded-[5px] border px-[11px] py-[5px] font-sans text-xs font-semibold',
                className,
            )}
            style={ckChipStyle(meta.tone)}
        >
            <StatusGlyph tone={meta.tone} glyph={meta.glyph} />
            <span>{visibleLabel}</span>
        </span>
    );
}

/**
 * A safe fallback for call sites whose background isn't one of
 * `StatusBadge`'s chip fill's contrast-verified surfaces. Historically
 * (Tasks 3/9/12), `ckChipStyle`'s ~15% tint measured ~4.3:1 for the
 * "danger" tone (offline/failed/rolled-back) against `--ck-surface` —
 * under the 4.5:1 AA threshold, because a HIGHER tint paradoxically
 * REDUCES contrast against same-colored text (it moves the background
 * toward the text's own color). Task 20 fixed this at the token source
 * (`ckChipStyle` now mixes 5%, not 15% — see that function's own
 * docblock in resources/js/lib/ck-tokens.ts) rather than here, so
 * `StatusBadge`'s chip fill itself now clears AA for every tone this app
 * uses. `StatusText` remains available as a plain, chip-free fallback for
 * call sites that want one — `AppShell.tsx`'s `ServerIdentityCard` and
 * `resources/js/features/config/DiffReview.tsx`'s risk indicator still
 * use it — but it is no longer required for AA on `StatusBadge`'s own
 * account. See docs/architecture/decisions.md (Tasks 3/9/12/20).
 */
export function StatusText({ status, label, className }: StatusBadgeProps) {
    const meta = STATUS_BADGE_META[status];
    const visibleLabel = label ?? meta.label;
    const accessibleName =
        label && label !== meta.label ? `${meta.label}: ${label}` : meta.label;

    return (
        <span
            role="status"
            aria-label={accessibleName}
            data-ck-status={status}
            className={cn(
                'inline-flex items-center gap-[7px] font-sans text-xs font-semibold',
                className,
            )}
            style={{ color: 'var(--ck-text)' }}
        >
            <StatusGlyph tone={meta.tone} glyph={meta.glyph} />
            <span>{visibleLabel}</span>
        </span>
    );
}
