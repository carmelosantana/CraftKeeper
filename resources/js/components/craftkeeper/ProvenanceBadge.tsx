import { cn } from '@/lib/utils';

/**
 * Shows where a piece of information or an action originated. Per the V1
 * plan ("Provenance is always visible: 'Built in,' 'Plugin,' 'Discovered,'
 * 'Catalog,' 'Hangar,' 'Modrinth,' or 'Manual.'") and
 * `Design/handoff/components.json`'s `ProvenanceBadge` ("Small square color
 * chip + label ... never color-only").
 *
 * The seven sources below are mapped onto the `--ck-provenance-*` tokens
 * from `design-tokens.json` (which enumerates a broader, non-plugin-scoped
 * provenance vocabulary — mounted-server/catalog/hangar/modrinth/
 * documentation/ai-provider/administrator/api-mcp — used elsewhere, e.g.
 * AIAnswer citations). No new color vocabulary is introduced: every
 * mapping below resolves to one of those existing `--ck-*` variables.
 */
const PROVENANCE_META = {
    'built-in': {
        label: 'Built in',
        colorVar: '--ck-provenance-documentation',
    },
    plugin: { label: 'Plugin', colorVar: '--ck-provenance-hangar' },
    discovered: {
        label: 'Discovered',
        colorVar: '--ck-provenance-mounted-server',
    },
    catalog: { label: 'Catalog', colorVar: '--ck-provenance-catalog' },
    hangar: { label: 'Hangar', colorVar: '--ck-provenance-hangar' },
    modrinth: { label: 'Modrinth', colorVar: '--ck-provenance-modrinth' },
    manual: { label: 'Manual', colorVar: '--ck-provenance-administrator' },
} as const;

export type ProvenanceSource = keyof typeof PROVENANCE_META;

export interface ProvenanceBadgeProps {
    source: ProvenanceSource;
    /** Overrides the visible label (e.g. a specific source name); the
     * source's canonical name is still used as the accessible name. */
    label?: string;
    className?: string;
}

export function ProvenanceBadge({
    source,
    label,
    className,
}: ProvenanceBadgeProps) {
    const meta = PROVENANCE_META[source];

    return (
        <span
            data-ck-provenance={source}
            className={cn(
                'inline-flex items-center gap-[6px] rounded-[5px] border px-[10px] py-[5px] font-sans text-[11.5px] font-semibold',
                className,
            )}
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
                    flex: 'none',
                    backgroundColor: `var(${meta.colorVar})`,
                }}
            />
            <span>{label ?? meta.label}</span>
        </span>
    );
}

export { PROVENANCE_META };
