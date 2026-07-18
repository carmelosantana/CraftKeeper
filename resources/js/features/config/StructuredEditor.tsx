import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { JsonValue } from '@/types/config';

/**
 * Structured mode: a generic nested object/array editor, per the plan's
 * "Structured mode renders nested objects/arrays." Works on ANY parsed
 * config tree, recognized or not — App\Http\Controllers\ConfigController::
 * buildStructuredData() has already replaced every schema-secret leaf's
 * value with the '••••••' sentinel before this component ever receives
 * `data`, so leaving a masked leaf untouched and submitting it back is
 * indistinguishable, from this component's point of view, from any other
 * unedited leaf — the server-side reconciler is what turns "still the
 * sentinel" into "no change" (see ConfigController::reconcileStructured()).
 *
 * Arrays are edited as raw JSON text (a single leaf), matching the
 * scalar-leaf model the backend's ConfigChange primitive is scoped to
 * (Task 7/8) — descending into individual array items is out of scope,
 * consistent with App\Config\ConfigRevisionService's own documented
 * best-effort limitation.
 */
export interface StructuredEditorProps {
    data: Record<string, JsonValue>;
    onChange: (data: Record<string, JsonValue>) => void;
}

function isPlainObject(value: unknown): value is Record<string, JsonValue> {
    return (
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value)
    );
}

function setAtPath(
    root: Record<string, JsonValue>,
    segments: string[],
    value: JsonValue,
): Record<string, JsonValue> {
    if (segments.length === 0) {
        return root;
    }

    const [head, ...rest] = segments;
    const clone: Record<string, JsonValue> = { ...root };

    if (rest.length === 0) {
        clone[head] = value;

        return clone;
    }

    const child = isPlainObject(clone[head]) ? clone[head] : {};
    clone[head] = setAtPath(child, rest, value);

    return clone;
}

function LeafControl({
    leafKey,
    value,
    onChange,
}: {
    leafKey: string;
    value: JsonValue;
    onChange: (value: JsonValue) => void;
}) {
    const id = `structured-leaf-${leafKey}`;
    const [text, setText] = useState(() =>
        typeof value === 'string' ? value : JSON.stringify(value),
    );

    if (typeof value === 'boolean') {
        return (
            <select
                id={id}
                value={String(value)}
                onChange={(event) => onChange(event.target.value === 'true')}
                className="h-9 rounded-md border px-2 font-mono text-[12px]"
                style={{
                    borderColor: 'var(--ck-border-strong)',
                    backgroundColor: 'var(--ck-surface)',
                    color: 'var(--ck-text)',
                }}
                data-test={`structured-leaf-${leafKey}`}
            >
                <option value="true">true</option>
                <option value="false">false</option>
            </select>
        );
    }

    return (
        <Input
            id={id}
            value={text}
            className="font-mono text-[12px]"
            onChange={(event) => {
                setText(event.target.value);

                if (typeof value === 'number') {
                    const parsed = Number(event.target.value);
                    onChange(Number.isNaN(parsed) ? event.target.value : parsed);
                } else if (Array.isArray(value) || isPlainObject(value)) {
                    try {
                        onChange(JSON.parse(event.target.value));
                    } catch {
                        // Leave the underlying value untouched until the
                        // JSON becomes valid again — the raw text is still
                        // shown so nothing the operator typed is lost.
                    }
                } else {
                    onChange(event.target.value);
                }
            }}
            data-test={`structured-leaf-${leafKey}`}
        />
    );
}

function Node({
    data,
    path,
    onLeafChange,
    depth,
}: {
    data: Record<string, JsonValue>;
    path: string[];
    onLeafChange: (segments: string[], value: JsonValue) => void;
    depth: number;
}) {
    return (
        <div
            className={cn('grid gap-[10px]', depth > 0 && 'border-l pl-[14px]')}
            style={{ borderColor: 'var(--ck-border)' }}
        >
            {Object.entries(data).map(([key, value]) => {
                const segments = [...path, key];
                const dotted = segments.join('.');

                if (isPlainObject(value)) {
                    return (
                        <div key={dotted} className="grid gap-[8px]">
                            <span
                                className="font-mono text-[12px] font-semibold"
                                style={{ color: 'var(--ck-text)' }}
                            >
                                {key}
                            </span>
                            <Node
                                data={value}
                                path={segments}
                                onLeafChange={onLeafChange}
                                depth={depth + 1}
                            />
                        </div>
                    );
                }

                return (
                    <div key={dotted} className="grid gap-[4px]">
                        <Label
                            htmlFor={`structured-leaf-${dotted}`}
                            className="font-mono text-[11.5px]"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            {dotted}
                        </Label>
                        <LeafControl
                            leafKey={dotted}
                            value={value}
                            onChange={(next) => onLeafChange(segments, next)}
                        />
                    </div>
                );
            })}
        </div>
    );
}

export function StructuredEditor({ data, onChange }: StructuredEditorProps) {
    return (
        <div
            className="rounded-[10px] border px-[14px] py-[14px]"
            style={{
                borderColor: 'var(--ck-border)',
                backgroundColor: 'var(--ck-surface)',
            }}
        >
            <Node
                data={data}
                path={[]}
                depth={0}
                onLeafChange={(segments, value) =>
                    onChange(setAtPath(data, segments, value))
                }
            />
        </div>
    );
}
