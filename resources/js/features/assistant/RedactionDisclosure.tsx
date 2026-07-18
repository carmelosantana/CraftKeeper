import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import { cn } from '@/lib/utils';
import type { RedactionDisclosureDTO } from '@/types/assistant';

/**
 * `Design/handoff/components.json` → `RedactionDisclosure`. Shown per
 * assistant turn (App\Models\AiMessage::redaction_disclosures) — "3
 * secrets masked" / "RCON password, API token ... are replaced with •••
 * before sending", or a distinct high-visibility notice when the turn was
 * sent UNREDACTED (only possible for a local Ollama provider after the
 * operator's explicit `ai.ollama.allow_unredacted` opt-in — see
 * App\Ai\ContextBuilder). Renders nothing when there is nothing to
 * disclose (a turn with no config/secret context at all).
 */
export interface RedactionDisclosureProps {
    disclosures: RedactionDisclosureDTO[];
    unredacted?: boolean;
    className?: string;
}

export function RedactionDisclosure({
    disclosures,
    unredacted = false,
    className,
}: RedactionDisclosureProps) {
    if (unredacted) {
        return (
            <div
                role="status"
                data-test="redaction-disclosure"
                data-ck-redaction="unredacted"
                className={cn(
                    'rounded-[8px] border px-[12px] py-[9px] text-[12px] leading-[1.5]',
                    className,
                )}
                style={ckSubtleSurfaceStyle('danger')}
            >
                <strong className="font-bold">Sent unredacted:</strong> the
                local Ollama opt-in is enabled for this conversation — this
                context was NOT masked before it left CraftKeeper.
            </div>
        );
    }

    if (disclosures.length === 0) {
        return null;
    }

    const totalOccurrences = disclosures.reduce(
        (sum, disclosure) => sum + disclosure.occurrences,
        0,
    );
    const labels = disclosures
        .map((disclosure) => disclosure.label)
        .filter((label): label is string => Boolean(label));

    return (
        <div
            role="status"
            data-test="redaction-disclosure"
            data-ck-redaction="masked"
            className={cn(
                'rounded-[8px] border px-[12px] py-[9px] text-[12px] leading-[1.5]',
                className,
            )}
            style={ckSubtleSurfaceStyle('warning')}
        >
            <strong className="font-bold">
                {disclosures.length}{' '}
                {disclosures.length === 1 ? 'secret' : 'secrets'} masked
            </strong>{' '}
            ({totalOccurrences}{' '}
            {totalOccurrences === 1 ? 'occurrence' : 'occurrences'}) —{' '}
            {labels.length > 0 ? labels.join(', ') : 'detected secret values'}{' '}
            {labels.length === 1 ? 'is' : 'are'} replaced with{' '}
            <code className="font-mono">••••••</code> before this context
            left CraftKeeper.
        </div>
    );
}
