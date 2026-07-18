import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ApiTokenController from '@/actions/App/Http/Controllers/Integrations/ApiTokenController';
import { PageState } from '@/components/craftkeeper/PageState';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/AppShell';
import type { ApiIntegrationsPageProps } from '@/types/integrations';

/**
 * Task 17's ambiguity resolution #7: create/list/revoke scoped /api/v1
 * tokens, and a link to the OpenAPI reference. A newly created token's
 * plaintext value is rendered from the `newToken` prop that
 * App\Http\Controllers\Integrations\ApiTokenController::store() attaches
 * to ONLY the single response immediately following creation — reloading
 * this page, or creating a second token, never brings it back (Sanctum
 * only ever stores its sha256 hash).
 */
export default function IntegrationsApi({
    tokens,
    availableScopes,
    openApiUrl,
    newToken,
}: ApiIntegrationsPageProps) {
    const [copied, setCopied] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        scopes: string[];
    }>({ name: '', scopes: [] });

    function toggleScope(value: string, checked: boolean) {
        setData(
            'scopes',
            checked
                ? [...data.scopes, value]
                : data.scopes.filter((scope) => scope !== value),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        setCopied(false);

        post(ApiTokenController.store.url(), {
            preserveScroll: true,
            onSuccess: () => reset('name', 'scopes'),
        });
    }

    function revoke(id: number, name: string) {
        if (
            !window.confirm(
                `Revoke the API token "${name}"? Any client using it will lose access immediately.`,
            )
        ) {
            return;
        }

        router.delete(ApiTokenController.destroy.url(id), {
            preserveScroll: true,
        });
    }

    async function copyNewToken() {
        if (!newToken) {
            return;
        }

        try {
            await navigator.clipboard.writeText(newToken.plainText);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    }

    return (
        <AppShell>
            <Head title="Integrations · API" />

            <header className="mb-[18px] flex flex-wrap items-center justify-between gap-[12px]">
                <div>
                    <h1
                        className="text-[20px] font-bold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        API
                    </h1>
                    <p
                        className="mt-[3px] text-[12.5px]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Scoped tokens for the versioned /api/v1 REST API. Each
                        token only ever carries the scopes chosen at creation —
                        a read scope never grants write, propose, apply, or rcon
                        access.
                    </p>
                </div>
                <a
                    href={openApiUrl}
                    target="_blank"
                    rel="noreferrer"
                    data-test="openapi-link"
                >
                    <Button type="button" variant="outline">
                        OpenAPI reference
                    </Button>
                </a>
            </header>

            {newToken && (
                <div
                    role="alert"
                    data-test="new-token-banner"
                    className="mb-[18px] rounded-[12px] border p-[16px]"
                    style={{
                        backgroundColor:
                            'color-mix(in srgb, var(--ck-warning) 9%, var(--ck-surface))',
                        borderColor:
                            'color-mix(in srgb, var(--ck-warning) 30%, transparent)',
                    }}
                >
                    <p
                        className="text-[13px] font-bold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        Token &quot;{newToken.name}&quot; created
                    </p>
                    <p
                        className="mt-[2px] text-[12px]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        Copy it now — for your security, CraftKeeper never shows
                        this value again after you leave this page.
                    </p>
                    <div className="mt-[10px] flex flex-wrap items-center gap-[10px]">
                        <code
                            data-test="new-token-value"
                            className="rounded-[8px] border px-[10px] py-[6px] font-mono text-[12.5px] break-all"
                            style={{
                                backgroundColor: 'var(--ck-surface)',
                                borderColor: 'var(--ck-border)',
                                color: 'var(--ck-text)',
                            }}
                        >
                            {newToken.plainText}
                        </code>
                        <Button type="button" size="sm" onClick={copyNewToken}>
                            {copied ? 'Copied' : 'Copy'}
                        </Button>
                    </div>
                </div>
            )}

            <section
                className="mb-[24px] rounded-[12px] border p-[16px]"
                style={{
                    backgroundColor: 'var(--ck-surface)',
                    borderColor: 'var(--ck-border)',
                }}
            >
                <h2
                    className="mb-[12px] text-[15px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Create a token
                </h2>

                <form onSubmit={submit} className="grid gap-[14px]">
                    <div className="grid gap-[6px]">
                        <Label htmlFor="token-name">Name</Label>
                        <Input
                            id="token-name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder="e.g. Backup automation"
                            maxLength={255}
                            data-test="token-name-input"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <fieldset className="grid gap-[8px]">
                        <legend
                            className="text-sm font-medium"
                            style={{ color: 'var(--ck-text)' }}
                        >
                            Scopes
                        </legend>
                        <div className="grid gap-[8px] sm:grid-cols-2">
                            {availableScopes.map((scope) => (
                                <label
                                    key={scope.value}
                                    htmlFor={`scope-${scope.value}`}
                                    className="flex items-start gap-[8px] rounded-[8px] border p-[10px]"
                                    style={{ borderColor: 'var(--ck-border)' }}
                                >
                                    <Checkbox
                                        id={`scope-${scope.value}`}
                                        checked={data.scopes.includes(
                                            scope.value,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleScope(
                                                scope.value,
                                                checked === true,
                                            )
                                        }
                                        data-test={`scope-checkbox-${scope.value}`}
                                    />
                                    <span>
                                        <span
                                            className="block text-[12.5px] font-semibold"
                                            style={{ color: 'var(--ck-text)' }}
                                        >
                                            {scope.label}
                                        </span>
                                        <span
                                            className="block font-mono text-[11px]"
                                            style={{
                                                color: 'var(--ck-text-2)',
                                            }}
                                        >
                                            {scope.value}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.scopes} />
                    </fieldset>

                    <div>
                        <Button
                            type="submit"
                            disabled={processing || data.scopes.length === 0}
                            data-test="create-token-button"
                        >
                            Create token
                        </Button>
                    </div>
                </form>
            </section>

            <section>
                <h2
                    className="mb-[12px] text-[15px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Existing tokens
                </h2>

                {tokens.length === 0 ? (
                    <PageState
                        state="empty"
                        title="No API tokens yet"
                        description="Create a scoped token above to start using /api/v1."
                    />
                ) : (
                    <ul className="grid gap-[10px]" data-test="token-list">
                        {tokens.map((token) => (
                            <li
                                key={token.id}
                                data-test="token-row"
                                className="flex flex-wrap items-center justify-between gap-[10px] rounded-[10px] border p-[12px]"
                                style={{
                                    backgroundColor: 'var(--ck-surface)',
                                    borderColor: 'var(--ck-border)',
                                }}
                            >
                                <div className="min-w-0">
                                    <div
                                        className="text-[13px] font-bold"
                                        style={{ color: 'var(--ck-text)' }}
                                    >
                                        {token.name}
                                    </div>
                                    <div className="mt-[4px] flex flex-wrap gap-[6px]">
                                        {token.abilities.map((ability) => (
                                            <span
                                                key={ability}
                                                className="rounded-[6px] border px-[6px] py-[2px] font-mono text-[10.5px]"
                                                style={{
                                                    borderColor:
                                                        'var(--ck-border)',
                                                    color: 'var(--ck-text-2)',
                                                }}
                                            >
                                                {ability}
                                            </span>
                                        ))}
                                    </div>
                                    <div
                                        className="mt-[4px] text-[11px]"
                                        style={{ color: 'var(--ck-text-2)' }}
                                    >
                                        Created {token.createdAt ?? 'unknown'}
                                        {token.lastUsedAt
                                            ? ` · Last used ${token.lastUsedAt}`
                                            : ' · Never used'}
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => revoke(token.id, token.name)}
                                    data-test="revoke-token-button"
                                >
                                    Revoke
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </AppShell>
    );
}
