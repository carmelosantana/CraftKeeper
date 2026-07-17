import type { ReactNode } from 'react';
import { ckChipStyle, ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import { cn } from '@/lib/utils';

/**
 * `Design/handoff/components.json` → `RestartRequiredBadge`: "warning
 * triangle + 'Restart' / 'Restart required'". Operational state is honest
 * and specific — a pending restart is never collapsed into "saved" (see
 * `Design/handoff/README.md`).
 */
function WarningTriangle({ size = 8 }: { size?: number }) {
    const height = Math.round(size * 1.6);

    return (
        <span
            aria-hidden="true"
            style={{
                flex: 'none',
                width: 0,
                height: 0,
                borderLeft: `${size / 1.6}px solid transparent`,
                borderRight: `${size / 1.6}px solid transparent`,
                borderBottom: `${height / 1.6}px solid var(--ck-warning)`,
            }}
        />
    );
}

export interface RestartRequiredProps {
    /** `chip` — compact inline indicator (top bar, list row). `banner` —
     * persistent, consequence-first notice (e.g. mobile Overview). */
    variant?: 'chip' | 'banner';
    /** Chip label; defaults to "Restart pending". */
    label?: string;
    /** Banner body copy; consequence-first, honest about state. */
    message?: ReactNode;
    className?: string;
}

export function RestartRequired({
    variant = 'chip',
    label = 'Restart pending',
    message = (
        <>
            <strong className="font-bold">Restart required</strong> to activate
            a saved change.
        </>
    ),
    className,
}: RestartRequiredProps) {
    if (variant === 'banner') {
        return (
            <div
                role="status"
                aria-label="Restart required"
                data-ck-restart-required="banner"
                className={cn(
                    'flex items-start gap-[10px] rounded-[11px] border px-[14px] py-[13px] font-sans text-xs leading-[1.45]',
                    className,
                )}
                style={ckSubtleSurfaceStyle('warning')}
            >
                <span className="mt-[4px]">
                    <WarningTriangle />
                </span>
                <span style={{ color: 'var(--ck-text)' }}>{message}</span>
            </div>
        );
    }

    return (
        <span
            role="status"
            aria-label="Restart required"
            data-ck-restart-required="chip"
            className={cn(
                'inline-flex items-center gap-[7px] rounded-[6px] border px-[11px] py-[6px] font-sans text-xs font-semibold',
                className,
            )}
            style={ckChipStyle('warning')}
        >
            <WarningTriangle />
            {label}
        </span>
    );
}
