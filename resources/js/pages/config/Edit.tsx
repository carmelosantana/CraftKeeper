import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ProvenanceBadge } from '@/components/craftkeeper/ProvenanceBadge';
import type { ProvenanceSource } from '@/components/craftkeeper/ProvenanceBadge';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { StatusBadge } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { DiffReview } from '@/features/config/DiffReview';
import { GuidedEditor } from '@/features/config/GuidedEditor';
import { SourceEditor } from '@/features/config/SourceEditor';
import { StructuredEditor } from '@/features/config/StructuredEditor';
import { useIsMobile } from '@/hooks/use-mobile';
import { AppShell } from '@/layouts/AppShell';
import type { ConfigEditProps, EditMode, JsonValue } from '@/types/config';

/**
 * Configuration editor — guided/structured/source tabs over ONE shared
 * review flow. Per the plan, "All modes converge on the same
 * ConfigChangeRequest" — this page never builds that request itself; it
 * only reconciles the operator's in-progress edit into a plain payload
 * (`{ mode, base_sha256, values | source }`) and posts it to
 * App\Http\Controllers\ConfigController::propose(), which does the actual
 * reconciliation server-side (see that controller's reconcileGuided()/
 * reconcileStructured()/reconcileSource()). The DiffReview panel that
 * appears after a successful propose() is a SPLIT PANE on desktop and a
 * BOTTOM SHEET on mobile — same component, same approve/cancel controls,
 * never hidden at either breakpoint.
 */
export default function ConfigEdit(props: ConfigEditProps) {
    const { file, guided, structured, source, proposal, historyUrl, sourceError } = props;
    const isMobile = useIsMobile();

    const [mode, setMode] = useState<EditMode>(guided ? 'guided' : 'structured');
    const [guidedValues, setGuidedValues] = useState<Record<string, JsonValue>>(
        () => {
            const initial: Record<string, JsonValue> = {};
            guided?.groups.forEach((group) =>
                group.fields.forEach((field) => {
                    initial[field.path] = field.currentValue as JsonValue;
                }),
            );

            return initial;
        },
    );
    const [structuredData, setStructuredData] = useState(structured.data);
    const [sourceValue, setSourceValue] = useState(source.contents);
    const [pending, setPending] = useState(false);

    function submit() {
        const base = { base_sha256: file.baseSha256, base_source: source.contents };
        const payload =
            mode === 'guided'
                ? { mode, ...base, values: guidedValues }
                : mode === 'structured'
                  ? { mode, ...base, values: structuredData }
                  : { mode, ...base, source: sourceValue };

        router.post(`/configurations/${file.path}`, payload, {
            preserveScroll: true,
            onStart: () => setPending(true),
            onFinish: () => setPending(false),
        });
    }

    function approve() {
        if (!proposal) {
            return;
        }

        router.post(
            `/configurations/operations/${proposal.operationId}/approve`,
            {},
            {
                preserveScroll: true,
                onStart: () => setPending(true),
                onFinish: () => setPending(false),
            },
        );
    }

    function reject() {
        if (!proposal) {
            return;
        }

        router.post(
            `/configurations/operations/${proposal.operationId}/reject`,
            {},
            {
                preserveScroll: true,
                onStart: () => setPending(true),
                onFinish: () => setPending(false),
            },
        );
    }

    const tabs: { key: EditMode; label: string; available: boolean }[] = [
        { key: 'guided', label: 'Guided form', available: guided !== null },
        { key: 'structured', label: 'Structured', available: true },
        { key: 'source', label: 'Source', available: true },
    ];

    return (
        <AppShell>
            <Head title={file.filename} />

            <nav
                aria-label="Breadcrumb"
                className="mb-[6px] text-[11.5px]"
                style={{ color: 'var(--ck-text-2)' }}
            >
                <Link
                    href="/configurations"
                    className="underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    Configurations
                </Link>{' '}
                / {file.path}
            </nav>

            <header className="mb-[18px] flex flex-wrap items-start justify-between gap-[12px]">
                <div>
                    <h1
                        className="text-[20px] font-bold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        {file.filename}
                    </h1>
                    <div
                        className="mt-[2px] flex flex-wrap items-center gap-[10px] font-mono text-[12px]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        <span>{file.path}</span>
                        <ProvenanceBadge
                            source={file.provenance as ProvenanceSource}
                        />
                        {file.validation.valid ? (
                            <StatusBadge status="completed" label="Valid" />
                        ) : (
                            <StatusBadge status="failed" label="Invalid" />
                        )}
                    </div>
                </div>
                <Link
                    href={historyUrl}
                    className="text-[12.5px] font-semibold underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    View history
                </Link>
            </header>

            <div className="grid gap-[20px] lg:grid-cols-[1fr_360px] lg:items-start">
                <div className="min-w-0">
                    <div
                        role="tablist"
                        aria-label="Editing mode"
                        className="mb-[16px] flex gap-[6px] border-b"
                        style={{ borderColor: 'var(--ck-border)' }}
                    >
                        {tabs
                            .filter((tab) => tab.available)
                            .map((tab) => (
                                <button
                                    key={tab.key}
                                    type="button"
                                    role="tab"
                                    aria-selected={mode === tab.key}
                                    onClick={() => setMode(tab.key)}
                                    data-test={`config-tab-${tab.key}`}
                                    className="rounded-t-[7px] px-[14px] py-[9px] text-[12.5px] font-semibold"
                                    style={
                                        mode === tab.key
                                            ? {
                                                  color: 'var(--ck-accent)',
                                                  borderBottom:
                                                      '2px solid var(--ck-accent)',
                                              }
                                            : { color: 'var(--ck-text-2)' }
                                    }
                                >
                                    {tab.label}
                                </button>
                            ))}
                    </div>

                    <div role="tabpanel">
                        {mode === 'guided' && guided && (
                            <GuidedEditor
                                groups={guided.groups}
                                values={guidedValues}
                                onChange={(path, value) =>
                                    setGuidedValues((prev) => ({
                                        ...prev,
                                        [path]: value,
                                    }))
                                }
                            />
                        )}
                        {mode === 'structured' && (
                            <StructuredEditor
                                data={structuredData}
                                onChange={setStructuredData}
                            />
                        )}
                        {mode === 'source' && (
                            <SourceEditor
                                value={sourceValue}
                                onChange={setSourceValue}
                                diagnostics={file.validation.diagnostics}
                                sourceError={sourceError}
                            />
                        )}
                    </div>

                    <div className="mt-[18px] flex items-center gap-[10px]">
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={pending}
                            data-test="config-propose"
                        >
                            {pending ? 'Reviewing…' : 'Review changes'}
                        </Button>
                        {file.validation.diagnostics.length > 0 && (
                            <RestartRequired
                                variant="chip"
                                label={`${file.validation.diagnostics.length} diagnostic(s)`}
                            />
                        )}
                    </div>
                </div>

                {proposal && (
                    <div
                        className={
                            isMobile
                                ? 'fixed inset-x-0 bottom-0 z-30 max-h-[75vh] overflow-auto rounded-t-[16px]'
                                : 'lg:sticky lg:top-[20px]'
                        }
                    >
                        <DiffReview
                            proposal={proposal}
                            onApprove={approve}
                            onReject={reject}
                            pending={pending}
                            variant={isMobile ? 'sheet' : 'panel'}
                        />
                    </div>
                )}
            </div>
        </AppShell>
    );
}
