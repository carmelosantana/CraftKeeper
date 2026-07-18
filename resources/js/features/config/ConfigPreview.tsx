import { Link } from '@inertiajs/react';
import { ProvenanceBadge } from '@/components/craftkeeper/ProvenanceBadge';
import type { ProvenanceSource } from '@/components/craftkeeper/ProvenanceBadge';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { StatusBadge } from '@/components/craftkeeper/StatusBadge';
import type { InventoryItemDTO } from '@/types/config';

/**
 * One inventory row/card. Renders as a card at every breakpoint (the plan's
 * "Tables become stacked cards below 768px" — a single-column card grid
 * already IS the stacked-card presentation, so there is no separate table
 * layout to collapse out of). `item.preview` is already a bounded, secret-
 * redacted source excerpt (App\Http\Controllers\ConfigController::
 * boundedPreview()) — this component never receives, and therefore cannot
 * render, a raw secret value.
 */
export interface ConfigPreviewProps {
    item: InventoryItemDTO;
}

export function ConfigPreview({ item }: ConfigPreviewProps) {
    return (
        <Link
            href={`/configurations/${item.path}`}
            data-test={`config-item-${item.path}`}
            className="grid gap-[8px] rounded-[10px] border px-[14px] py-[13px] transition-colors hover:border-(--ck-accent)"
            style={{
                borderColor: 'var(--ck-border)',
                backgroundColor: 'var(--ck-surface)',
            }}
        >
            <div className="flex flex-wrap items-start justify-between gap-[8px]">
                <div className="min-w-0">
                    <div
                        className="truncate text-[13px] font-bold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        {item.filename}
                    </div>
                    <div
                        className="truncate font-mono text-[11px]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        {item.path}
                    </div>
                </div>
                <ProvenanceBadge source={item.provenance as ProvenanceSource} />
            </div>

            {item.readable ? (
                <>
                    {item.preview && (
                        <pre
                            className="overflow-hidden rounded-[7px] border px-[10px] py-[8px] font-mono text-[11px] leading-[1.5] whitespace-pre-wrap"
                            style={{
                                borderColor: 'var(--ck-border)',
                                backgroundColor: 'var(--ck-bg)',
                                color: 'var(--ck-text-2)',
                                maxHeight: 96,
                            }}
                        >
                            {item.preview}
                        </pre>
                    )}
                    <div className="flex flex-wrap items-center gap-[8px] text-[11px]">
                        {item.valid === false && (
                            <StatusBadge status="failed" label="Invalid" />
                        )}
                        {item.recognized ? (
                            <span style={{ color: 'var(--ck-text-2)' }}>
                                Recognized · guided editing available
                            </span>
                        ) : (
                            <span style={{ color: 'var(--ck-text-2)' }}>
                                Generic · source editing only
                            </span>
                        )}
                        {item.restartImpact && item.restartImpact !== 'none' && (
                            <RestartRequired
                                variant="chip"
                                label={
                                    item.restartImpact === 'restart'
                                        ? 'Up to restart required'
                                        : 'Up to reload required'
                                }
                            />
                        )}
                    </div>
                </>
            ) : (
                <div
                    role="status"
                    className="text-[11.5px]"
                    style={{ color: 'var(--ck-warning)' }}
                >
                    Unavailable — this file could not be read.
                </div>
            )}
        </Link>
    );
}
