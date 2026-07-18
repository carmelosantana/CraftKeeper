import { useMemo } from 'react';
import { StatusGlyph } from '@/components/craftkeeper/StatusBadge';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import { cn } from '@/lib/utils';
import type { ProposedOperationSummary, ToolCallDTO } from '@/types/assistant';

/**
 * Renders the outcome of `propose_config_change`/`compose_rcon_command` —
 * the two AI tools that ever create an App\Models\Operation (see their
 * own docblocks). This panel NEVER offers an approve/reject control of
 * its own: it only links to the EXISTING configuration/console review
 * surfaces (App\Http\Controllers\ConfigController::edit()/
 * App\Http\Controllers\ConsoleController::index(), both of which accept
 * `?operation={id}` to load a specific pending proposal) — the normal
 * operation approval flow, unchanged by this task. There is no code path
 * in this component, or anywhere in the assistant UI, that can approve or
 * execute anything.
 */
export interface ApprovalPanelProps {
    toolCall: ToolCallDTO;
    className?: string;
}

function parseSummary(toolCall: ToolCallDTO): ProposedOperationSummary | null {
    if (toolCall.phase !== 'result' || toolCall.status !== 'success' || !toolCall.summary) {
        return null;
    }

    try {
        const parsed = JSON.parse(toolCall.summary) as Record<string, unknown>;

        if (typeof parsed.operation_id !== 'string') {
            return null;
        }

        return parsed as unknown as ProposedOperationSummary;
    } catch {
        return null;
    }
}

export function ApprovalPanel({ toolCall, className }: ApprovalPanelProps) {
    const summary = useMemo(() => parseSummary(toolCall), [toolCall]);

    if (summary === null) {
        return null;
    }

    const reviewUrl =
        toolCall.name === 'propose_config_change' && summary.path
            ? `/configurations/${summary.path}?operation=${summary.operation_id}`
            : toolCall.name === 'compose_rcon_command'
              ? `/server/console?operation=${summary.operation_id}`
              : null;

    const elevated = summary.risk === 'elevated';

    return (
        <div
            data-ck-assistant-approval
            data-test="assistant-approval-panel"
            className={cn(
                'flex flex-col gap-[10px] rounded-[10px] border px-[14px] py-[12px]',
                className,
            )}
            style={{
                backgroundColor: 'var(--ck-elevated)',
                borderColor: elevated
                    ? 'color-mix(in srgb, var(--ck-warning) 30%, var(--ck-border))'
                    : 'var(--ck-border)',
            }}
        >
            <div className="flex flex-wrap items-center gap-[10px]">
                <span
                    className="text-[13px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    {toolCall.name === 'propose_config_change'
                        ? 'Proposed configuration change'
                        : 'Proposed console command'}
                </span>
                {summary.risk && (
                    <span
                        role="status"
                        className="inline-flex items-center gap-[6px] text-[11.5px] font-semibold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        <StatusGlyph
                            tone={elevated ? 'warning' : 'success'}
                            glyph={elevated ? 'triangle' : 'check'}
                        />
                        {elevated ? 'Elevated risk' : 'Standard risk'}
                    </span>
                )}
            </div>

            {summary.command && (
                <div
                    className="font-mono text-[12px]"
                    style={{ color: 'var(--ck-text)' }}
                >
                    {summary.command}
                </div>
            )}

            {summary.consequence && (
                <p
                    className="text-[12.5px] leading-[1.5]"
                    style={{ color: 'var(--ck-text)' }}
                >
                    {summary.consequence}
                </p>
            )}

            <div
                role="status"
                className="rounded-[7px] border px-[10px] py-[7px] text-[11.5px] font-semibold"
                style={ckSubtleSurfaceStyle('info')}
            >
                Awaiting human approval — nothing has been applied or run.
            </div>

            {reviewUrl && (
                <a
                    href={reviewUrl}
                    data-test="assistant-review-link"
                    className="inline-flex w-fit items-center rounded-[7px] px-[13px] py-[8px] text-[12.5px] font-semibold"
                    style={{
                        backgroundColor: 'var(--ck-accent)',
                        color: 'var(--ck-accent-fg)',
                    }}
                >
                    Review &amp; approve
                </a>
            )}
        </div>
    );
}
