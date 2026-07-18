import { useEcho } from '@laravel/echo-react';
import { useEffect, useState } from 'react';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import type { StatusBadgeStatus } from '@/components/craftkeeper/StatusBadge';
import { useRealtimeStatus } from '@/hooks/use-realtime-status';
import { cn } from '@/lib/utils';
import type {
    OperationLifecycleStatus,
    OperationSummaryDTO,
} from '@/types/server';

/**
 * The shared "an Operation is moving through propose -> approve ->
 * execute" primitive (Task 12's own brief: "build the shared
 * OperationProgress primitive"). Subscribes to the operation's private
 * `operations.{id}` channel (App\Events\OperationUpdated, Task 5) for
 * live status updates; the initial `operation` prop (from the page's own
 * Inertia response, already fresh as of the last request) is what renders
 * before/without a live update ever arriving, so this component is never
 * blank or stuck just because Reverb isn't reachable — see
 * resources/js/hooks/use-realtime-status.ts.
 */
export interface OperationProgressProps {
    operation: OperationSummaryDTO;
    className?: string;
}

const STATUS_BADGE_FOR: Record<OperationLifecycleStatus, StatusBadgeStatus> =
    {
        proposed: 'scheduled',
        approved: 'in-progress',
        running: 'in-progress',
        succeeded: 'completed',
        failed: 'failed',
        rejected: 'failed',
        rolled_back: 'rolled-back',
    };

const LABEL_FOR: Record<OperationLifecycleStatus, string> = {
    proposed: 'Awaiting approval',
    approved: 'Approved',
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
    rejected: 'Rejected',
    rolled_back: 'Rolled back',
};

interface OperationUpdatedPayload {
    id: string;
    status: OperationLifecycleStatus;
    outcome: string | null;
    error_code: string | null;
}

export function OperationProgress({
    operation,
    className,
}: OperationProgressProps) {
    const [status, setStatus] = useState(operation.status);
    const [outcome, setOutcome] = useState(operation.outcome);
    const [errorCode, setErrorCode] = useState(operation.errorCode);
    const realtime = useRealtimeStatus();

    // The parent page (e.g. resources/js/features/console/
    // CommandComposer.tsx) re-renders this component with a fresh
    // `operation` prop after a full Inertia navigation (approve/reject),
    // WITHOUT necessarily remounting it — a plain `useState(operation.
    // status)` initializer only runs on the very first mount and would
    // otherwise keep showing stale state (e.g. "Awaiting approval") even
    // after the server has already recorded a terminal outcome. This
    // effect re-syncs local state whenever the operation PROP itself
    // reports something new — a genuinely fresher server-confirmed value
    // — without stomping on a possibly-newer live websocket update in
    // between two prop changes.
    useEffect(() => {
        setStatus(operation.status);
        setOutcome(operation.outcome);
        setErrorCode(operation.errorCode);
    }, [operation.id, operation.status, operation.outcome, operation.errorCode]);

    useEcho<OperationUpdatedPayload>(
        `operations.${operation.id}`,
        '.operation.updated',
        (payload) => {
            setStatus(payload.status);
            setOutcome(payload.outcome);
            setErrorCode(payload.error_code);
        },
        [operation.id],
    );

    return (
        <div
            data-ck-operation-progress
            data-test="operation-progress"
            className={cn('flex flex-col gap-[6px]', className)}
        >
            <div className="flex flex-wrap items-center gap-[10px]">
                <StatusText
                    status={STATUS_BADGE_FOR[status]}
                    label={LABEL_FOR[status]}
                />
                {realtime !== 'connected' && (
                    <span
                        className="text-[11px] font-medium"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        {realtime === 'connecting'
                            ? 'Connecting to live updates…'
                            : 'Live updates unavailable — showing the last known state.'}
                    </span>
                )}
            </div>
            {outcome && (
                <p
                    className="text-[12.5px] leading-[1.5]"
                    style={{ color: 'var(--ck-text)' }}
                >
                    {outcome}
                </p>
            )}
            {errorCode && (
                <p
                    className="font-mono text-[11px]"
                    style={{ color: 'var(--ck-danger)' }}
                >
                    Error code: {errorCode}
                </p>
            )}
        </div>
    );
}
