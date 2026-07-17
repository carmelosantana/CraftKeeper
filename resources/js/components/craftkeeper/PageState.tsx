import type { ReactNode } from 'react';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { Skeleton } from '@/components/ui/skeleton';
import { ckToneColor } from '@/lib/ck-tokens';
import type { CkTone } from '@/lib/ck-tokens';
import { cn } from '@/lib/utils';

/**
 * `Design/handoff/components.json` → `PageState`. "Unavailable telemetry
 * is labeled honestly (e.g. CPU 'not reported'), never simulated" — every
 * non-`normal` state below renders an explicit, honest reason rather than
 * a blank or a fabricated value.
 */
export type PageStateKind =
    | 'loading'
    | 'empty'
    | 'normal'
    | 'partial-data'
    | 'offline-server'
    | 'rcon-disconnected'
    | 'permission-denied'
    | 'read-only-fs'
    | 'invalid-config'
    | 'operation-running'
    | 'operation-failed'
    | 'pending-restart'
    | 'ai-disabled'
    | 'ai-unavailable'
    | 'marketplace-unavailable'
    | 'catalog-stale';

type PageStateGlyph = 'square' | 'triangle' | 'cross' | 'spinner' | 'ring';

type PageStateMetaEntry = {
    title: string;
    description: string;
    tone: CkTone;
    glyph: PageStateGlyph;
};

const PAGE_STATE_META: Partial<Record<PageStateKind, PageStateMetaEntry>> = {
    empty: {
        title: 'Nothing here yet',
        description: 'There is no data to show for this view yet.',
        tone: 'neutral',
        glyph: 'square',
    },
    'offline-server': {
        title: 'Server offline',
        description: "CraftKeeper can't reach the Minecraft server process.",
        tone: 'danger',
        glyph: 'square',
    },
    'rcon-disconnected': {
        title: 'RCON disconnected',
        description:
            'Console and live commands are unavailable until the RCON connection is restored.',
        tone: 'danger',
        glyph: 'square',
    },
    'permission-denied': {
        title: 'Permission denied',
        description: 'Your account does not have access to this area.',
        tone: 'danger',
        glyph: 'cross',
    },
    'read-only-fs': {
        title: 'Read-only filesystem',
        description:
            'The mounted server directory is not writable; changes cannot be saved.',
        tone: 'warning',
        glyph: 'triangle',
    },
    'invalid-config': {
        title: 'Configuration is invalid',
        description:
            'This file could not be parsed. Fix the highlighted errors before continuing.',
        tone: 'danger',
        glyph: 'cross',
    },
    'operation-running': {
        title: 'Operation in progress',
        description:
            'This may take a moment. You can navigate away — it will keep running.',
        tone: 'info',
        glyph: 'spinner',
    },
    'operation-failed': {
        title: 'Operation failed',
        description:
            'The last operation did not complete. Review the details before retrying.',
        tone: 'danger',
        glyph: 'cross',
    },
    'ai-disabled': {
        title: 'Assistant disabled',
        description:
            'No AI provider is configured. Configure one in Integrations to enable this.',
        tone: 'neutral',
        glyph: 'square',
    },
    'ai-unavailable': {
        title: 'Assistant unavailable',
        description:
            'The configured AI provider did not respond. Other features are unaffected.',
        tone: 'warning',
        glyph: 'triangle',
    },
    'marketplace-unavailable': {
        title: 'Marketplace unavailable',
        description:
            'Hangar and Modrinth could not be reached. Installed plugins are unaffected.',
        tone: 'warning',
        glyph: 'triangle',
    },
    'catalog-stale': {
        title: 'Catalog data is stale',
        description:
            'The last successful refresh was a while ago — results may not reflect the latest releases.',
        tone: 'warning',
        glyph: 'ring',
    },
};

function StateGlyph({ tone, glyph }: { tone: CkTone; glyph: PageStateGlyph }) {
    const color = ckToneColor(tone);
    const size = 16;

    switch (glyph) {
        case 'triangle':
            return (
                <span
                    aria-hidden="true"
                    style={{
                        width: 0,
                        height: 0,
                        borderLeft: `${size / 2}px solid transparent`,
                        borderRight: `${size / 2}px solid transparent`,
                        borderBottom: `${size * 0.85}px solid ${color}`,
                    }}
                />
            );
        case 'spinner':
            return (
                <span
                    aria-hidden="true"
                    className="ck-spin"
                    style={{
                        width: size,
                        height: size,
                        borderRadius: 9999,
                        border: `3px solid ${color}`,
                        borderRightColor: 'transparent',
                    }}
                />
            );
        case 'ring':
            return (
                <span
                    aria-hidden="true"
                    style={{
                        width: size,
                        height: size,
                        borderRadius: 4,
                        border: `2px solid ${color}`,
                    }}
                />
            );
        case 'cross':
            return (
                <span
                    aria-hidden="true"
                    style={{
                        fontSize: size,
                        fontWeight: 700,
                        color,
                        lineHeight: 1,
                    }}
                >
                    ✕
                </span>
            );
        case 'square':
        default:
            return (
                <span
                    aria-hidden="true"
                    style={{
                        width: size,
                        height: size,
                        borderRadius: 4,
                        backgroundColor: color,
                        opacity: 0.85,
                    }}
                />
            );
    }
}

export interface PageStateProps {
    state: PageStateKind;
    /** Overrides the default title for this state. */
    title?: string;
    /** Overrides the default, honest description for this state. */
    description?: ReactNode;
    /** e.g. a retry button. Rendered below the description. */
    action?: ReactNode;
    /** Loaded content. Rendered directly for `normal`; rendered *below*
     * the banner for `partial-data`; ignored for every other state. */
    children?: ReactNode;
    className?: string;
}

export function PageState({
    state,
    title,
    description,
    action,
    children,
    className,
}: PageStateProps) {
    if (state === 'normal') {
        return <>{children}</>;
    }

    if (state === 'loading') {
        return (
            <div
                role="status"
                aria-label="Loading"
                data-ck-page-state="loading"
                className={cn('grid gap-[10px]', className)}
            >
                <Skeleton className="h-[86px] w-full" />
                <Skeleton className="h-[16px] w-2/3" />
                <Skeleton className="h-[16px] w-1/2" />
            </div>
        );
    }

    if (state === 'pending-restart') {
        return (
            <RestartRequired
                variant="banner"
                className={className}
                message={
                    description ?? (
                        <>
                            <strong className="font-bold">
                                Restart required
                            </strong>{' '}
                            {title ??
                                'to activate a saved change before it takes effect.'}
                        </>
                    )
                }
            />
        );
    }

    const meta = PAGE_STATE_META[state] ?? {
        title: 'Unable to display this view',
        description: 'This state is not fully specified yet.',
        tone: 'neutral' as CkTone,
        glyph: 'square' as PageStateGlyph,
    };

    if (state === 'partial-data') {
        return (
            <div className={cn('grid gap-[14px]', className)}>
                <div
                    role="status"
                    aria-label="Partial data"
                    data-ck-page-state="partial-data"
                    className="flex items-center gap-[10px] rounded-[8px] border px-[13px] py-[10px] font-sans text-xs"
                    style={{
                        backgroundColor:
                            'color-mix(in srgb, var(--ck-warning) 9%, var(--ck-surface))',
                        borderColor:
                            'color-mix(in srgb, var(--ck-warning) 26%, transparent)',
                        color: 'var(--ck-text)',
                    }}
                >
                    <StateGlyph tone="warning" glyph="triangle" />
                    <span>
                        {description ??
                            'Some information could not be loaded and is shown as unavailable rather than guessed.'}
                    </span>
                </div>
                {children}
            </div>
        );
    }

    const role = meta.tone === 'danger' ? 'alert' : 'status';

    return (
        <div
            role={role}
            aria-label={title ?? meta.title}
            data-ck-page-state={state}
            className={cn(
                'flex flex-col items-center gap-[10px] rounded-[10px] border px-[24px] py-[40px] text-center font-sans',
                className,
            )}
            style={{
                backgroundColor: 'var(--ck-surface)',
                borderColor: 'var(--ck-border)',
            }}
        >
            <StateGlyph tone={meta.tone} glyph={meta.glyph} />
            <div
                className="text-[15px] font-bold"
                style={{ color: 'var(--ck-text)' }}
            >
                {title ?? meta.title}
            </div>
            <div
                className="max-w-[420px] text-[12.5px] leading-[1.5]"
                style={{ color: 'var(--ck-text-2)' }}
            >
                {description ?? meta.description}
            </div>
            {action}
        </div>
    );
}
