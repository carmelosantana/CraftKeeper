import { StatusGlyph } from '@/components/craftkeeper/StatusBadge';
import { ckSubtleSurfaceStyle, ckToneColor } from '@/lib/ck-tokens';
import type { CkTone } from '@/lib/ck-tokens';
import { cn } from '@/lib/utils';
import type {
    CompatibilityEvidenceEntryDTO,
    PluginCompatibilityStateValue,
} from '@/types/plugins';

/**
 * `Design/handoff/components.json` → `CompatibilityIndicator`: "Concrete
 * signals only — no invented unified 'trust score'." Backed by
 * App\Plugins\PluginCompatibilityService's verdict (never a bare guess —
 * see that class's docblock: `Unknown` is the honest default absent
 * positive evidence) AND the evidence list behind it, always shown
 * together — an operator can see WHY a plugin was assessed a given way,
 * never just an opaque label.
 */
const STATE_META: Record<
    PluginCompatibilityStateValue,
    { label: string; tone: CkTone; glyph: 'check' | 'cross' | 'triangle' | 'square' }
> = {
    compatible: { label: 'Compatible', tone: 'success', glyph: 'check' },
    incompatible: { label: 'Incompatible', tone: 'danger', glyph: 'cross' },
    warning: { label: 'Compatible with warnings', tone: 'warning', glyph: 'triangle' },
    unknown: { label: 'Unknown metadata', tone: 'neutral', glyph: 'square' },
};

function supportGlyph(supports: boolean | null): { symbol: string; tone: CkTone } {
    if (supports === true) {
        return { symbol: '✓', tone: 'success' };
    }

    if (supports === false) {
        return { symbol: '✕', tone: 'danger' };
    }

    return { symbol: '•', tone: 'neutral' };
}

export interface CompatibilityEvidenceProps {
    state: PluginCompatibilityStateValue;
    evidence: CompatibilityEvidenceEntryDTO[];
    className?: string;
    /** Compact renders only the state chip (list pages); full also renders
     * every evidence row (detail/plan review). */
    variant?: 'compact' | 'full';
}

export function CompatibilityEvidence({
    state,
    evidence,
    className,
    variant = 'full',
}: CompatibilityEvidenceProps) {
    const meta = STATE_META[state];

    return (
        <div
            data-ck-compatibility={state}
            className={cn('flex flex-col gap-[10px]', className)}
        >
            <span
                role="status"
                aria-label={`Compatibility: ${meta.label}`}
                className="inline-flex w-fit items-center gap-[7px] font-sans text-[12.5px] font-semibold"
                style={{ color: 'var(--ck-text)' }}
            >
                <StatusGlyph tone={meta.tone} glyph={meta.glyph} />
                {meta.label}
            </span>

            {variant === 'full' && evidence.length > 0 && (
                <ul className="grid gap-[6px]" data-test="compatibility-evidence-list">
                    {evidence.map((entry, index) => {
                        const support = supportGlyph(entry.supportsCompatibility);

                        return (
                            <li
                                key={index}
                                className="flex items-start gap-[8px] rounded-[7px] border px-[11px] py-[8px] text-[12px] leading-[1.5]"
                                style={ckSubtleSurfaceStyle(support.tone)}
                            >
                                <span
                                    aria-hidden="true"
                                    className="mt-[1px] font-bold"
                                    style={{ color: ckToneColor(support.tone) }}
                                >
                                    {support.symbol}
                                </span>
                                <span>
                                    <span
                                        className="mr-[6px] font-mono text-[10.5px]"
                                        style={{ color: 'var(--ck-text-2)' }}
                                    >
                                        [{entry.source}]
                                    </span>
                                    <span style={{ color: 'var(--ck-text)' }}>
                                        {entry.summary}
                                    </span>
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}

            {variant === 'full' && evidence.length === 0 && (
                <p className="text-[12px]" style={{ color: 'var(--ck-text-2)' }}>
                    No compatibility evidence is available for this plugin yet.
                </p>
            )}
        </div>
    );
}
