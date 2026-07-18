import { useMemo } from 'react';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { STATUS_BADGE_META, StatusGlyph } from '@/components/craftkeeper/StatusBadge';
import type { StatusBadgeStatus } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { ckSubtleSurfaceStyle, ckToneColor } from '@/lib/ck-tokens';
import type { CkTone } from '@/lib/ck-tokens';
import { cn } from '@/lib/utils';
import type { ProposalDTO } from '@/types/config';

/**
 * The ONE review surface all three edit modes (guided/structured/source)
 * share — per the V1 plan, "then show unified diff, consequences,
 * validation, restart effect, risk, and approve/cancel." Every value this
 * component renders (the diff, the field before/after rows) is already
 * redacted server-side (App\Http\Controllers\ConfigController::
 * presentOperation()) — this component never receives, and therefore can
 * never leak, a raw secret value.
 *
 * `variant="panel"` is the desktop split-pane presentation; `variant="sheet"`
 * is the mobile bottom-sheet presentation (Task 3's Drawer/BottomSheet
 * pattern) — both render the SAME approve/reject controls, un-hidden and
 * keyboard-reachable, satisfying "no hidden approval controls on mobile."
 */
export interface DiffReviewProps {
    proposal: ProposalDTO;
    onApprove: () => void;
    onReject: () => void;
    pending?: boolean;
    variant?: 'panel' | 'sheet';
    className?: string;
}

const RISK_STATUS: Record<ProposalDTO['risk'], StatusBadgeStatus> = {
    standard: 'completed',
    elevated: 'pending-restart',
};

const RESTART_LABEL: Record<ProposalDTO['restartImpact'], string> = {
    none: 'Takes effect immediately',
    reload: 'Needs a reload',
    restart: 'Needs a server restart',
};

interface DiffLine {
    kind: 'context' | 'add' | 'del' | 'header';
    text: string;
}

function parseDiff(diff: string): DiffLine[] {
    return diff
        .split('\n')
        .filter((line, index, all) => !(line === '' && index === all.length - 1))
        .map((line) => {
            if (line.startsWith('+++') || line.startsWith('---')) {
                return { kind: 'header', text: line };
            }

            if (line.startsWith('+')) {
                return { kind: 'add', text: line.slice(1) };
            }

            if (line.startsWith('-')) {
                return { kind: 'del', text: line.slice(1) };
            }

            return { kind: 'context', text: line.slice(1) };
        });
}

function DiffLineRow({ line }: { line: DiffLine }) {
    if (line.kind === 'header') {
        // --ck-text-2, not --ck-text-3: this is the diff's own "---"/"+++"
        // file-label line, real readable text, not decorative — --ck-text-3
        // is not reliably AA-safe as body text (see docs/architecture/
        // decisions.md, Task 3 and Task 9).
        return (
            <div
                className="px-3 py-[2px] font-mono text-[11px]"
                style={{ color: 'var(--ck-text-2)' }}
            >
                {line.text}
            </div>
        );
    }

    const style =
        line.kind === 'add'
            ? {
                  backgroundColor: 'var(--ck-syntax-diff-add-bg)',
                  color: 'var(--ck-syntax-diff-add-fg)',
              }
            : line.kind === 'del'
              ? {
                    backgroundColor: 'var(--ck-syntax-diff-del-bg)',
                    color: 'var(--ck-syntax-diff-del-fg)',
                }
              : { color: 'var(--ck-text-2)' };

    const prefix = line.kind === 'add' ? '+' : line.kind === 'del' ? '-' : ' ';

    return (
        <div
            className="whitespace-pre-wrap break-all px-3 py-[1px] font-mono text-[11.5px] leading-[1.6]"
            style={style}
        >
            {prefix}
            {line.text}
        </div>
    );
}

function ValidationList({ proposal }: { proposal: ProposalDTO }) {
    if (proposal.diagnostics.length === 0) {
        return (
            <div
                role="status"
                className="flex items-center gap-[8px] text-[12px] font-semibold"
                style={{ color: 'var(--ck-success)' }}
            >
                <span aria-hidden="true">✓</span>
                Validation passed
            </div>
        );
    }

    return (
        <ul className="grid gap-[6px]">
            {proposal.diagnostics.map((diagnostic, index) => {
                const tone: CkTone =
                    diagnostic.severity === 'error' ? 'danger' : 'warning';

                return (
                    <li
                        key={index}
                        role={diagnostic.severity === 'error' ? 'alert' : 'status'}
                        className="rounded-[7px] border px-[11px] py-[8px] text-[12px] leading-[1.5]"
                        style={ckSubtleSurfaceStyle(tone)}
                    >
                        <span
                            className="mr-[6px] font-semibold uppercase"
                            style={{ color: ckToneColor(tone) }}
                        >
                            {diagnostic.severity}
                        </span>
                        {diagnostic.path && (
                            <span
                                className="mr-[6px] font-mono text-[11px]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                [{diagnostic.path}]
                            </span>
                        )}
                        <span style={{ color: 'var(--ck-text)' }}>
                            {diagnostic.message}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

export function DiffReview({
    proposal,
    onApprove,
    onReject,
    pending = false,
    variant = 'panel',
    className,
}: DiffReviewProps) {
    const lines = useMemo(() => parseDiff(proposal.diff), [proposal.diff]);
    const isSheet = variant === 'sheet';
    const terminal =
        proposal.status !== 'proposed' && proposal.status !== 'approved';

    return (
        <section
            aria-label="Pending change review"
            data-ck-diff-review={variant}
            className={cn(
                'flex flex-col gap-[16px] rounded-[12px] border',
                isSheet ? 'p-[16px]' : 'p-[18px]',
                className,
            )}
            style={{
                backgroundColor: 'var(--ck-elevated)',
                borderColor: 'var(--ck-border)',
                boxShadow: isSheet ? 'var(--ck-shadow-lg, none)' : undefined,
            }}
        >
            <header className="flex flex-wrap items-center justify-between gap-[10px]">
                <h2
                    className="text-[14px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    {proposal.kind === 'restore'
                        ? 'Review restore'
                        : 'Review change'}
                </h2>
                {/* Not the tinted StatusBadge chip: its ~15%-fill background
                    is only contrast-verified against --ck-surface (see
                    design-tokens.json and AppShell.tsx's ServerIdentityCard
                    docblock for the same, earlier-discovered issue) — this
                    panel sits on --ck-elevated, where that fill fails AA
                    at this text size. StatusGlyph (shape only) + --ck-text
                    label reproduces the same "never color alone" guarantee
                    and holds AA on every surface. */}
                <span
                    role="status"
                    aria-label={STATUS_BADGE_META[RISK_STATUS[proposal.risk]].label}
                    className="inline-flex items-center gap-[7px] text-[12px] font-semibold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    <StatusGlyph
                        tone={STATUS_BADGE_META[RISK_STATUS[proposal.risk]].tone}
                        glyph={STATUS_BADGE_META[RISK_STATUS[proposal.risk]].glyph}
                    />
                    {proposal.risk === 'elevated' ? 'Elevated risk' : 'Standard risk'}
                </span>
            </header>

            {proposal.normalizationWarning && (
                <div
                    role="status"
                    className="rounded-[8px] border px-[12px] py-[9px] text-[12px] leading-[1.5]"
                    style={ckSubtleSurfaceStyle('warning')}
                >
                    <strong className="font-bold">Reformatting notice:</strong>{' '}
                    Applying this change will reformat the file and may drop
                    comments or reorder keys.
                </div>
            )}

            <RestartRequired
                variant="chip"
                label={RESTART_LABEL[proposal.restartImpact]}
            />

            {/* Consequences BEFORE the confirm control, per the plan's
                "Destructive and high-risk actions show consequences before
                the confirmation control." */}
            <div className="grid gap-[8px]">
                <h3
                    className="text-[11px] font-bold tracking-wide uppercase"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Changed fields ({proposal.fields.length})
                </h3>
                <ul className="grid gap-[6px]">
                    {proposal.fields.map((field) => (
                        <li
                            key={field.path}
                            className="rounded-[7px] border px-[11px] py-[8px] text-[12px]"
                            style={{
                                borderColor: 'var(--ck-border)',
                                backgroundColor: 'var(--ck-surface)',
                            }}
                        >
                            <div
                                className="font-mono text-[11.5px] font-semibold"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                {field.path}
                            </div>
                            <div
                                className="mt-[2px] font-mono text-[11px]"
                                style={{ color: 'var(--ck-text-2)' }}
                            >
                                <span style={{ color: 'var(--ck-syntax-diff-del-fg)' }}>
                                    − {field.before ?? '(none)'}
                                </span>{' '}
                                <span style={{ color: 'var(--ck-syntax-diff-add-fg)' }}>
                                    + {field.after ?? '(none)'}
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            </div>

            <div className="grid gap-[8px]">
                <h3
                    className="text-[11px] font-bold tracking-wide uppercase"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Validation
                </h3>
                <ValidationList proposal={proposal} />
            </div>

            {proposal.documentation.length > 0 && (
                <div className="grid gap-[6px]">
                    <h3
                        className="text-[11px] font-bold tracking-wide uppercase"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Documentation
                    </h3>
                    <ul className="grid gap-[3px] text-[12px]">
                        {proposal.documentation.map((doc) => (
                            <li key={doc.path}>
                                {/* --ck-text, not --ck-accent: on this
                                    panel's --ck-elevated background,
                                    accent-colored text falls under 4.5:1
                                    AA (see design-tokens.json's own
                                    accent-on-tint contrast caveat, echoed
                                    in AppShell.tsx) — the underline alone
                                    still marks this as a link. */}
                                <a
                                    href={doc.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-medium underline"
                                    style={{ color: 'var(--ck-text)' }}
                                >
                                    {doc.path} docs ↗
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid gap-[6px]">
                <h3
                    className="text-[11px] font-bold tracking-wide uppercase"
                    style={{ color: 'var(--ck-text-2)' }}
                >
                    Unified diff
                </h3>
                <div
                    className="max-h-[280px] overflow-auto rounded-[8px] border py-[6px]"
                    style={{
                        backgroundColor: 'var(--ck-bg)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    {lines.length === 0 ? (
                        <div
                            className="px-3 py-[4px] font-mono text-[11.5px]"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            No textual differences.
                        </div>
                    ) : (
                        lines.map((line, index) => (
                            <DiffLineRow key={index} line={line} />
                        ))
                    )}
                </div>
            </div>

            {terminal && proposal.outcome && (
                <div
                    role={proposal.status === 'failed' ? 'alert' : 'status'}
                    className="rounded-[8px] border px-[12px] py-[9px] text-[12px]"
                    style={ckSubtleSurfaceStyle(
                        proposal.status === 'failed' ? 'danger' : 'success',
                    )}
                >
                    {proposal.outcome}
                </div>
            )}

            {/* Approve/cancel controls are ALWAYS rendered in normal
                document flow (never `hidden`, never `display:none` at any
                breakpoint) so they are reachable by keyboard and touch on
                mobile — see resources/js/features/config/DiffReview.test.tsx
                and tests/e2e/configuration.spec.ts's mobile assertion. */}
            {!terminal && (
                <div className="flex flex-wrap items-center gap-[10px] pt-[4px]">
                    <Button
                        type="button"
                        onClick={onApprove}
                        disabled={pending || !proposal.valid}
                        data-test="diff-review-approve"
                    >
                        {pending ? 'Applying…' : 'Approve & save'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onReject}
                        disabled={pending}
                        data-test="diff-review-reject"
                    >
                        Discard changes
                    </Button>
                    {!proposal.valid && (
                        <span
                            className="text-[11.5px] font-medium"
                            style={{ color: 'var(--ck-danger)' }}
                        >
                            Fix validation errors before approving.
                        </span>
                    )}
                </div>
            )}
        </section>
    );
}
