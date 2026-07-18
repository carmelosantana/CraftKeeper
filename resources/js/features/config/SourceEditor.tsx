import { useMemo, useRef } from 'react';
import type { DiagnosticDTO } from '@/types/config';

/**
 * Source mode: a monospaced (JetBrains Mono) raw-text editor with a line
 * gutter and inline diagnostics, per the plan's "Source mode uses a
 * monospaced editor with diagnostics." The text this component displays
 * is ALREADY redacted (App\Http\Controllers\ConfigController::
 * redactedSource() masks every schema-secret field's value before it
 * becomes the `value` prop) — an operator who never touches a masked span
 * submits the same '••••••' sentinel back, which the server's
 * reconcileSource() treats as "unchanged" (see its docblock for the full
 * round-trip design, and docs/architecture/decisions.md's Task 9 entry).
 *
 * This is a plain `<textarea>`, not a full syntax-highlighting code
 * editor (no CodeMirror/Monaco dependency exists in this project yet) —
 * line numbers and diagnostics are rendered around it instead of inline
 * gutter squiggles. A richer editor can be layered in later without any
 * change to the ConfigChangeRequest contract this feeds.
 */
export interface SourceEditorProps {
    value: string;
    onChange: (value: string) => void;
    diagnostics?: DiagnosticDTO[];
    sourceError?: string;
}

export function SourceEditor({
    value,
    onChange,
    diagnostics = [],
    sourceError,
}: SourceEditorProps) {
    const gutterRef = useRef<HTMLDivElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    const lineCount = useMemo(
        () => Math.max(1, value.split('\n').length),
        [value],
    );

    function syncScroll() {
        if (gutterRef.current && textareaRef.current) {
            gutterRef.current.scrollTop = textareaRef.current.scrollTop;
        }
    }

    return (
        <div className="grid gap-[10px]">
            {sourceError && (
                <div
                    role="alert"
                    className="rounded-[8px] border px-[12px] py-[9px] text-[12px]"
                    style={{
                        borderColor: 'var(--ck-danger)',
                        backgroundColor:
                            'color-mix(in srgb, var(--ck-danger) 10%, var(--ck-surface))',
                        color: 'var(--ck-text)',
                    }}
                >
                    <strong className="font-bold">Could not parse:</strong>{' '}
                    {sourceError}
                </div>
            )}

            <div
                className="flex overflow-hidden rounded-[10px] border"
                style={{
                    borderColor: 'var(--ck-border)',
                    backgroundColor: 'var(--ck-bg)',
                }}
            >
                <div
                    ref={gutterRef}
                    aria-hidden="true"
                    className="select-none overflow-hidden px-[10px] py-[12px] text-right font-mono text-[12px] leading-[1.6]"
                    style={{ color: 'var(--ck-text-3)' }}
                >
                    {Array.from({ length: lineCount }, (_, index) => (
                        <div key={index}>{index + 1}</div>
                    ))}
                </div>
                <label htmlFor="source-editor" className="sr-only">
                    Configuration source
                </label>
                <textarea
                    id="source-editor"
                    ref={textareaRef}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    onScroll={syncScroll}
                    spellCheck={false}
                    autoComplete="off"
                    autoCapitalize="off"
                    className="min-h-[360px] flex-1 resize-y px-[10px] py-[12px] font-mono text-[12px] leading-[1.6] outline-none"
                    style={{
                        backgroundColor: 'transparent',
                        color: 'var(--ck-text)',
                    }}
                    data-test="source-editor"
                />
            </div>

            {diagnostics.length > 0 && (
                <ul className="grid gap-[4px] text-[11.5px]">
                    {diagnostics.map((diagnostic, index) => (
                        <li
                            key={index}
                            style={{
                                color:
                                    diagnostic.severity === 'error'
                                        ? 'var(--ck-danger)'
                                        : 'var(--ck-warning)',
                            }}
                        >
                            {diagnostic.line ? `Line ${diagnostic.line}: ` : ''}
                            {diagnostic.message}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
