import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ckToneColor } from '@/lib/ck-tokens';
import type { CkTone } from '@/lib/ck-tokens';
import { SECRET_MASK } from '@/types/config';
import type { GuidedFieldDTO, GuidedGroupDTO, JsonValue } from '@/types/config';

/**
 * Guided mode: schema fields + inline official documentation, per the
 * plan's "Guided mode uses schema fields and inline official
 * documentation." Every field's `currentValue` prop already arrives
 * pre-redacted for secret fields (App\Http\Controllers\ConfigController::
 * buildGuided() sends InputRedactor::MASK, never the real value) — this
 * component only ever displays what it was given, so leaving a secret
 * field's control untouched round-trips the sentinel back on submit,
 * which the server treats as "no change" (see ConfigController::
 * reconcileGuided()).
 */
export interface GuidedEditorProps {
    groups: GuidedGroupDTO[];
    values: Record<string, JsonValue>;
    onChange: (path: string, value: JsonValue) => void;
}

const RISK_TONE: Record<GuidedFieldDTO['risk'], CkTone> = {
    low: 'success',
    medium: 'warning',
    high: 'danger',
};

function FieldControl({
    field,
    value,
    onChange,
}: {
    field: GuidedFieldDTO;
    value: JsonValue;
    onChange: (value: JsonValue) => void;
}) {
    const controlId = `guided-field-${field.path}`;

    if (field.type === 'boolean') {
        return (
            <div className="flex items-center gap-[8px]">
                <Checkbox
                    id={controlId}
                    checked={value === true || value === 'true'}
                    onCheckedChange={(checked) => onChange(checked === true)}
                    data-test={`guided-field-${field.path}`}
                />
                <Label htmlFor={controlId} className="text-[12.5px]">
                    {value === true || value === 'true' ? 'Enabled' : 'Disabled'}
                </Label>
            </div>
        );
    }

    if (field.allowedValues && field.allowedValues.length > 0) {
        return (
            <Select
                value={String(value ?? '')}
                onValueChange={(next) => onChange(next)}
            >
                <SelectTrigger id={controlId} data-test={`guided-field-${field.path}`}>
                    <SelectValue placeholder="Choose a value" />
                </SelectTrigger>
                <SelectContent>
                    {field.allowedValues.map((option) => (
                        <SelectItem key={String(option)} value={String(option)}>
                            {String(option)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    return (
        <Input
            id={controlId}
            type={
                field.type === 'integer' || field.type === 'number'
                    ? 'number'
                    : field.secret
                      ? 'password'
                      : 'text'
            }
            autoComplete="off"
            value={value === null || value === undefined ? '' : String(value)}
            min={field.range?.min ?? undefined}
            max={field.range?.max ?? undefined}
            onChange={(event) => onChange(event.target.value)}
            data-test={`guided-field-${field.path}`}
        />
    );
}

function GuidedField({
    field,
    value,
    onChange,
}: {
    field: GuidedFieldDTO;
    value: JsonValue;
    onChange: (value: JsonValue) => void;
}) {
    const controlId = `guided-field-${field.path}`;
    const edited = String(value ?? '') !== String(field.currentValue ?? '');

    return (
        <div
            className="grid gap-[8px] rounded-[9px] border px-[14px] py-[13px]"
            style={{
                borderColor: edited ? 'var(--ck-accent)' : 'var(--ck-border)',
                backgroundColor: 'var(--ck-surface)',
            }}
        >
            <div className="flex flex-wrap items-start justify-between gap-[8px]">
                <div>
                    <Label
                        htmlFor={controlId}
                        className="text-[13px] font-semibold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        {field.title}
                    </Label>
                    <div
                        className="font-mono text-[11px]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        {field.path}
                    </div>
                </div>
                <div className="flex items-center gap-[6px]">
                    {edited && (
                        // --ck-text, not --ck-accent-hover: at 10px bold on
                        // this 18% accent tint, accent-colored text falls
                        // under 4.5:1 AA — the accent tint background alone
                        // (plus the field card's own accent border) still
                        // signals "edited"; --ck-text keeps the label
                        // itself readable.
                        <span
                            className="rounded-[4px] px-[7px] py-[2px] text-[10px] font-bold tracking-wide uppercase"
                            style={{
                                backgroundColor:
                                    'color-mix(in srgb, var(--ck-accent) 18%, transparent)',
                                color: 'var(--ck-text)',
                            }}
                        >
                            Edited
                        </span>
                    )}
                    <span
                        className="rounded-[4px] px-[7px] py-[2px] text-[10px] font-bold tracking-wide uppercase"
                        style={{
                            backgroundColor: 'var(--ck-surface-2)',
                            color: ckToneColor(RISK_TONE[field.risk]),
                        }}
                    >
                        {field.risk} risk
                    </span>
                </div>
            </div>

            <p
                className="text-[12px] leading-[1.5]"
                style={{ color: 'var(--ck-text-2)' }}
            >
                {field.description}
            </p>

            {field.secret && (
                <p
                    className="text-[11.5px] font-semibold"
                    style={{ color: 'var(--ck-warning)' }}
                >
                    Secret value — shown as {SECRET_MASK}. Type a new value to
                    replace it; leave it untouched to keep the current one.
                </p>
            )}

            <FieldControl field={field} value={value} onChange={onChange} />

            <div className="flex flex-wrap items-center gap-[10px] text-[11px]">
                <span style={{ color: 'var(--ck-text-2)' }}>
                    Default: {String(field.default ?? '(none)')}
                </span>
                {field.range && (
                    <span style={{ color: 'var(--ck-text-2)' }}>
                        Range {field.range.min ?? '–∞'}–{field.range.max ?? '∞'}
                    </span>
                )}
                <RestartRequired
                    variant="chip"
                    label={
                        field.restartImpact === 'none'
                            ? 'No restart'
                            : field.restartImpact === 'reload'
                              ? 'Reload — no restart'
                              : 'Restart required'
                    }
                />
                <a
                    href={field.documentationUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="font-semibold underline"
                    style={{ color: 'var(--ck-accent)' }}
                >
                    Docs ↗
                </a>
            </div>
        </div>
    );
}

export function GuidedEditor({ groups, values, onChange }: GuidedEditorProps) {
    return (
        <div className="grid gap-[22px]">
            {groups.map((group) => {
                const essential = group.fields.filter((f) => !f.advanced);
                const advanced = group.fields.filter((f) => f.advanced);

                return (
                    <section key={group.title} className="grid gap-[10px]">
                        <h3
                            className="text-[11px] font-bold tracking-wide uppercase"
                            style={{ color: 'var(--ck-text-2)' }}
                        >
                            {group.title}
                        </h3>
                        <div className="grid gap-[10px]">
                            {essential.map((field) => (
                                <GuidedField
                                    key={field.path}
                                    field={field}
                                    value={values[field.path] ?? (field.currentValue as JsonValue)}
                                    onChange={(value) => onChange(field.path, value)}
                                />
                            ))}
                        </div>
                        {advanced.length > 0 && (
                            <details className="grid gap-[10px]">
                                <summary
                                    className="cursor-pointer text-[12px] font-semibold"
                                    style={{ color: 'var(--ck-accent)' }}
                                >
                                    Show {advanced.length} advanced setting
                                    {advanced.length === 1 ? '' : 's'} —
                                    rarely changed, still editable
                                </summary>
                                <div className="grid gap-[10px]">
                                    {advanced.map((field) => (
                                        <GuidedField
                                            key={field.path}
                                            field={field}
                                            value={
                                                values[field.path] ??
                                                (field.currentValue as JsonValue)
                                            }
                                            onChange={(value) =>
                                                onChange(field.path, value)
                                            }
                                        />
                                    ))}
                                </div>
                            </details>
                        )}
                    </section>
                );
            })}
        </div>
    );
}
