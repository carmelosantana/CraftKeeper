import { router } from '@inertiajs/react';
import { useState } from 'react';
import { StatusGlyph } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { OperationProgress } from '@/features/operations/OperationProgress';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import { cn } from '@/lib/utils';
import type {
    ComposePreviewDTO,
    PendingOperationDTO,
} from '@/types/server';

/**
 * The elevated-command approval gate (this task's own brief, verbatim):
 * typing a command and pressing "Compose command" classifies it via
 * App\Console\CommandPolicy (through App\Http\Controllers\
 * ConsoleController::compose(), a pure, read-only preview — see that
 * method's own docblock) and shows its CONSEQUENCE before any confirm
 * control exists. An Elevated command additionally shows "Approval
 * required" and offers only "Request approval" (which proposes an
 * Operation but still never touches RCON); the actual send only ever
 * happens from a SEPARATE, later "Approve & send" click on the resulting
 * pending-operation panel (App\Http\Controllers\ConsoleController::
 * approve()) — never from this composer directly. A Safe command instead
 * offers "Run now", the audited lighter path (runSafeCommand()).
 *
 * The operator's typed-but-unsent text lives in ordinary React state
 * (`command`), never reset by a websocket reconnect/loss event — see
 * resources/js/hooks/use-realtime-status.ts, which this component does
 * not even depend on for the input itself. Composing is a partial Inertia
 * reload (`only: ['composePreview']`, `preserveState`/`preserveScroll`),
 * never a full navigation, so the input is never remounted by it either.
 *
 * `variant="sheet"` (the resources/js/pages/server/Console.tsx page picks
 * this below 768px, via `useIsMobile()` — the same split of
 * responsibility resources/js/features/config/DiffReview.tsx already
 * uses) pins the WHOLE composer — input, consequence preview, and the
 * approval panel — to the bottom of the viewport as a scrollable sheet.
 * Every control inside it is the identical markup as `variant="panel"`,
 * just repositioned — never hidden, never `display:none` — so approval
 * stays keyboard- and touch-reachable on mobile.
 */
export interface CommandComposerProps {
    rcon: { available: boolean; reason: string | null };
    pendingOperation: PendingOperationDTO | null;
    /** Server-computed initial preview — populated when the page itself
     * was loaded with `?command=`, e.g. from
     * resources/js/pages/server/Players.tsx's "Kick…"/"Op…" links. Seeds
     * BOTH the input text and the consequence preview with no extra
     * client round trip, since App\Http\Controllers\ConsoleController's
     * `composePreviewProp()` already reads from the query string on a
     * normal GET. */
    composePreview?: ComposePreviewDTO | null;
    variant?: 'panel' | 'sheet';
    className?: string;
}

export function CommandComposer({
    rcon,
    pendingOperation: initialPendingOperation,
    composePreview = null,
    variant = 'panel',
    className,
}: CommandComposerProps) {
    const [command, setCommand] = useState(composePreview?.command ?? '');
    const [preview, setPreview] = useState<ComposePreviewDTO | null>(
        composePreview,
    );
    const [pendingOperation, setPendingOperation] = useState(
        initialPendingOperation,
    );
    const [composing, setComposing] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const showApprovalPanel =
        pendingOperation !== null &&
        (pendingOperation.status === 'proposed' ||
            pendingOperation.status === 'approved' ||
            pendingOperation.status === 'running');
    const showOutcomePanel = pendingOperation !== null && !showApprovalPanel;

    function compose() {
        const trimmed = command.trim();

        if (trimmed === '') {
            return;
        }

        router.post(
            '/server/console',
            { command: trimmed },
            {
                only: ['composePreview'],
                preserveState: true,
                preserveScroll: true,
                onStart: () => setComposing(true),
                onFinish: () => setComposing(false),
                onSuccess: (page) => {
                    const next = page.props
                        .composePreview as ComposePreviewDTO | null;
                    setPreview(next);
                },
            },
        );
    }

    // requestApproval()/approve()/reject()/runNow() are all FULL Inertia
    // visits (unlike compose()'s partial reload) — the server redirects to
    // a fresh GET /server/console[?operation=id]. Inertia reuses this same
    // mounted `server/Console` page component across that navigation
    // rather than remounting it, so a `useState(propValue)` initializer
    // does NOT pick up the new prop on its own; every handler below
    // explicitly reads the fresh value back off `page.props` in its
    // `onSuccess` callback. This is what makes the approval panel actually
    // appear right after "Request approval", not just after some
    // unrelated future re-render.
    function requestApproval() {
        if (preview === null) {
            return;
        }

        router.post(
            '/server/console/propose',
            { command: preview.command },
            {
                onStart: () => setConfirming(true),
                onFinish: () => setConfirming(false),
                onSuccess: (page) => {
                    setPendingOperation(
                        page.props
                            .pendingOperation as PendingOperationDTO | null,
                    );
                    setPreview(null);
                },
            },
        );
    }

    function runNow() {
        if (preview === null) {
            return;
        }

        router.post(
            '/server/console/run',
            { command: preview.command },
            {
                onStart: () => setConfirming(true),
                onFinish: () => setConfirming(false),
                onSuccess: () => {
                    setPreview(null);
                    setCommand('');
                },
            },
        );
    }

    function approve() {
        if (pendingOperation === null) {
            return;
        }

        router.post(
            `/server/console/operations/${pendingOperation.id}/approve`,
            {},
            {
                onStart: () => setConfirming(true),
                onFinish: () => setConfirming(false),
                onSuccess: (page) => {
                    setPendingOperation(
                        page.props
                            .pendingOperation as PendingOperationDTO | null,
                    );
                },
            },
        );
    }

    function reject() {
        if (pendingOperation === null) {
            return;
        }

        router.post(
            `/server/console/operations/${pendingOperation.id}/reject`,
            {},
            {
                onStart: () => setConfirming(true),
                onFinish: () => setConfirming(false),
                onSuccess: () => {
                    setPendingOperation(null);
                    setPreview(null);
                    setCommand('');
                },
            },
        );
    }

    const isSheet = variant === 'sheet';

    return (
        <section
            aria-label="Console command composer"
            data-ck-command-composer={variant}
            className={cn(
                'flex flex-col gap-[14px]',
                isSheet &&
                    'fixed inset-x-0 bottom-0 z-30 max-h-[75vh] overflow-auto rounded-t-[16px] border-t px-[16px] py-[14px] shadow-lg',
                className,
            )}
            style={
                isSheet
                    ? {
                          backgroundColor: 'var(--ck-surface)',
                          borderColor: 'var(--ck-border)',
                      }
                    : undefined
            }
        >
            {!rcon.available && (
                <div
                    role="status"
                    className="rounded-[8px] border px-[12px] py-[9px] text-[12px] leading-[1.5]"
                    style={ckSubtleSurfaceStyle('warning')}
                >
                    <strong className="font-bold">RCON unavailable:</strong>{' '}
                    {rcon.reason ?? 'commands cannot be confirmed to reach the server right now.'}
                </div>
            )}

            <div className="flex flex-col gap-[8px] sm:flex-row sm:items-center">
                <Input
                    data-testid="command-input"
                    data-test="command-input"
                    aria-label="Console command"
                    placeholder="Type a command, e.g. stop, list, say hello"
                    value={command}
                    onChange={(event) => setCommand(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            compose();
                        }
                    }}
                    className="font-mono"
                />
                <Button
                    type="button"
                    onClick={compose}
                    disabled={composing || command.trim() === ''}
                    data-test="compose-command"
                >
                    {composing ? 'Composing…' : 'Compose command'}
                </Button>
            </div>

            {preview && (
                <div
                    data-ck-compose-preview
                    data-test="compose-preview"
                    className="flex flex-col gap-[10px] rounded-[10px] border px-[14px] py-[12px]"
                    style={{
                        backgroundColor: 'var(--ck-elevated)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <div className="flex flex-wrap items-center gap-[8px]">
                        <span
                            className="font-mono text-[12.5px] font-semibold"
                            style={{ color: 'var(--ck-text)' }}
                        >
                            {preview.normalizedCommand}
                        </span>
                        <span
                            role="status"
                            className="inline-flex items-center gap-[6px] text-[11.5px] font-semibold"
                            style={{
                                color:
                                    preview.risk === 'elevated'
                                        ? 'var(--ck-warning)'
                                        : 'var(--ck-success)',
                            }}
                        >
                            <StatusGlyph
                                tone={
                                    preview.risk === 'elevated'
                                        ? 'warning'
                                        : 'success'
                                }
                                glyph={
                                    preview.risk === 'elevated'
                                        ? 'triangle'
                                        : 'check'
                                }
                            />
                            {preview.risk === 'elevated'
                                ? 'Elevated'
                                : 'Safe'}
                        </span>
                    </div>

                    {/* Consequences are shown BEFORE any confirm control —
                        the elevated-command gate this task exists to
                        implement. */}
                    <p
                        data-test="compose-consequence"
                        className="text-[12.5px] leading-[1.5]"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        {preview.consequence}
                    </p>

                    {preview.requiresApproval && (
                        <p
                            data-test="approval-required"
                            className="text-[12px] font-bold"
                            style={{ color: 'var(--ck-warning)' }}
                        >
                            Approval required
                        </p>
                    )}

                    <div className="flex flex-wrap gap-[10px]">
                        {preview.requiresApproval ? (
                            <Button
                                type="button"
                                onClick={requestApproval}
                                disabled={confirming}
                                data-test="request-approval"
                            >
                                {confirming
                                    ? 'Requesting…'
                                    : 'Request approval'}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                onClick={runNow}
                                disabled={confirming}
                                data-test="run-now"
                            >
                                {confirming ? 'Running…' : 'Run now'}
                            </Button>
                        )}
                    </div>
                </div>
            )}

            {showApprovalPanel && pendingOperation && (
                <div
                    data-ck-console-approval={
                        pendingOperation.risk === 'elevated'
                            ? 'panel'
                            : undefined
                    }
                    data-test="console-approval-panel"
                    className="flex flex-col gap-[12px] rounded-[12px] border p-[16px]"
                    style={{
                        backgroundColor: 'var(--ck-elevated)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <h3
                        className="text-[13px] font-bold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        Review command
                    </h3>
                    <span
                        className="font-mono text-[12.5px]"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        {pendingOperation.target}
                    </span>
                    <p
                        className="text-[12.5px] leading-[1.5]"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        {pendingOperation.consequence}
                    </p>
                    {pendingOperation.status === 'proposed' && (
                        <p
                            className="text-[12px] font-bold"
                            style={{ color: 'var(--ck-warning)' }}
                        >
                            Approval required
                        </p>
                    )}
                    <OperationProgress operation={pendingOperation} />
                    {/* Approve/reject are ALWAYS rendered in normal
                        document flow — never `hidden`, never
                        `display:none` at any breakpoint — so they remain
                        reachable on mobile, matching
                        resources/js/features/config/DiffReview.tsx's same
                        rule. */}
                    {pendingOperation.status === 'proposed' && (
                        <div className="flex flex-wrap items-center gap-[10px]">
                            <Button
                                type="button"
                                onClick={approve}
                                disabled={confirming}
                                data-test="approve-command"
                            >
                                {confirming ? 'Sending…' : 'Approve & send'}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={reject}
                                disabled={confirming}
                                data-test="reject-command"
                            >
                                Discard
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {showOutcomePanel && pendingOperation && (
                <div
                    data-test="console-operation-outcome"
                    className="rounded-[10px] border px-[14px] py-[12px]"
                    style={{
                        backgroundColor: 'var(--ck-surface)',
                        borderColor: 'var(--ck-border)',
                    }}
                >
                    <OperationProgress operation={pendingOperation} />
                </div>
            )}
        </section>
    );
}
