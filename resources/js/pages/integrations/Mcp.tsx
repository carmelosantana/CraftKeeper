import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import McpGrantController from '@/actions/App/Http/Controllers/Integrations/McpGrantController';
import { PageState } from '@/components/craftkeeper/PageState';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppShell } from '@/layouts/AppShell';
import type {
    McpGrantDTO,
    McpGrantState,
    McpIntegrationsPageProps,
} from '@/types/integrations';

/**
 * Task 18: the MCP OAuth integration management page. Every capability an
 * integration is granted comes from the checkboxes below — App\Models\
 * McpGrant's own `scopes` column, the SAME ceiling
 * App\Policies\McpGrantPolicy enforces on every /mcp/craftkeeper call
 * regardless of what an OAuth token negotiation later asks for (see that
 * model's own docblock). There is no "approve" action anywhere on this
 * page: a connection created here can only PROPOSE changes through MCP —
 * every proposal still needs a human to approve it in the normal
 * CraftKeeper review UI.
 */
export default function IntegrationsMcp({
    connectionUrl,
    authorizationEndpoint,
    tokenEndpoint,
    availableScopes,
    grants,
}: McpIntegrationsPageProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        display_name: string;
        redirect_uri: string;
        scopes: string[];
        expires_in_days: string;
    }>({ display_name: '', redirect_uri: '', scopes: [], expires_in_days: '' });

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

        post(McpGrantController.store.url(), {
            preserveScroll: true,
            onSuccess: () =>
                reset('display_name', 'redirect_uri', 'scopes', 'expires_in_days'),
        });
    }

    function revoke(grant: McpGrantDTO) {
        if (
            !window.confirm(
                `Revoke the MCP connection "${grant.displayName}"? Any client using it will lose access immediately.`,
            )
        ) {
            return;
        }

        router.delete(McpGrantController.destroy.url(grant.id), {
            preserveScroll: true,
        });
    }

    function stateLabel(state: McpGrantState): string {
        switch (state) {
            case 'active':
                return 'Active';
            case 'revoked':
                return 'Revoked';
            case 'expired':
                return 'Expired';
        }
    }

    function stateColor(state: McpGrantState): string {
        switch (state) {
            case 'active':
                return 'var(--ck-success)';
            case 'revoked':
            case 'expired':
                return 'var(--ck-danger)';
        }
    }

    return (
        <AppShell>
            <Head title="Integrations · MCP" />

            <header className="mb-[18px] flex flex-wrap items-center justify-between gap-[12px]">
                <div>
                    <h1
                        className="text-[20px] font-bold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        MCP
                    </h1>
                    <p
                        className="mt-[3px] text-[12.5px]"
                        style={{ color: 'var(--ck-text-2)' }}
                    >
                        OAuth-authorized MCP clients. Every connection can only PROPOSE
                        config, plugin, or safe-RCON changes — there is no approval tool;
                        a human must always approve in CraftKeeper before anything
                        changes.
                    </p>
                </div>
                <a href="/integrations/api" data-test="api-integrations-link">
                    <Button type="button" variant="outline">
                        API tokens
                    </Button>
                </a>
            </header>

            <section
                className="mb-[24px] rounded-[12px] border p-[16px]"
                style={{
                    backgroundColor: 'var(--ck-surface)',
                    borderColor: 'var(--ck-border)',
                }}
            >
                <h2
                    className="mb-[8px] text-[15px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Connection
                </h2>
                <dl className="grid gap-[6px] text-[12.5px]">
                    <div className="flex flex-wrap gap-[6px]">
                        <dt style={{ color: 'var(--ck-text-2)' }}>MCP endpoint:</dt>
                        <dd
                            className="font-mono break-all"
                            style={{ color: 'var(--ck-text)' }}
                            data-test="mcp-connection-url"
                        >
                            {connectionUrl}
                        </dd>
                    </div>
                    <div className="flex flex-wrap gap-[6px]">
                        <dt style={{ color: 'var(--ck-text-2)' }}>
                            Authorization endpoint:
                        </dt>
                        <dd
                            className="font-mono break-all"
                            style={{ color: 'var(--ck-text)' }}
                        >
                            {authorizationEndpoint}
                        </dd>
                    </div>
                    <div className="flex flex-wrap gap-[6px]">
                        <dt style={{ color: 'var(--ck-text-2)' }}>Token endpoint:</dt>
                        <dd
                            className="font-mono break-all"
                            style={{ color: 'var(--ck-text)' }}
                        >
                            {tokenEndpoint}
                        </dd>
                    </div>
                </dl>
                <p className="mt-[10px] text-[11px]" style={{ color: 'var(--ck-text-3)' }}>
                    Authorization-code + PKCE only — no password grant, no client-credentials
                    grant, no dynamic client registration, no anonymous access.
                </p>
            </section>

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
                    New connection
                </h2>

                <form onSubmit={submit} className="grid gap-[14px]">
                    <div className="grid gap-[6px] sm:grid-cols-2">
                        <div className="grid gap-[6px]">
                            <Label htmlFor="mcp-display-name">Name</Label>
                            <Input
                                id="mcp-display-name"
                                value={data.display_name}
                                onChange={(event) =>
                                    setData('display_name', event.target.value)
                                }
                                placeholder="e.g. Claude Desktop"
                                maxLength={255}
                                data-test="mcp-display-name-input"
                            />
                            <InputError message={errors.display_name} />
                        </div>
                        <div className="grid gap-[6px]">
                            <Label htmlFor="mcp-redirect-uri">Redirect URI</Label>
                            <Input
                                id="mcp-redirect-uri"
                                value={data.redirect_uri}
                                onChange={(event) =>
                                    setData('redirect_uri', event.target.value)
                                }
                                placeholder="https://client.example/callback"
                                maxLength={2048}
                                data-test="mcp-redirect-uri-input"
                            />
                            <InputError message={errors.redirect_uri} />
                        </div>
                    </div>

                    <div className="grid gap-[6px] sm:max-w-[220px]">
                        <Label htmlFor="mcp-expires-in-days">
                            Expires after (days, optional)
                        </Label>
                        <Input
                            id="mcp-expires-in-days"
                            value={data.expires_in_days}
                            onChange={(event) =>
                                setData('expires_in_days', event.target.value)
                            }
                            placeholder="Never"
                            inputMode="numeric"
                            data-test="mcp-expires-in-days-input"
                        />
                        <InputError message={errors.expires_in_days} />
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
                                    htmlFor={`mcp-scope-${scope.value}`}
                                    className="flex items-start gap-[8px] rounded-[8px] border p-[10px]"
                                    style={{ borderColor: 'var(--ck-border)' }}
                                >
                                    <Checkbox
                                        id={`mcp-scope-${scope.value}`}
                                        checked={data.scopes.includes(scope.value)}
                                        onCheckedChange={(checked) =>
                                            toggleScope(scope.value, checked === true)
                                        }
                                        data-test={`mcp-scope-checkbox-${scope.value}`}
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
                                            style={{ color: 'var(--ck-text-2)' }}
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
                            data-test="create-mcp-connection-button"
                        >
                            Create connection
                        </Button>
                    </div>
                </form>
            </section>

            <section>
                <h2
                    className="mb-[12px] text-[15px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Existing connections
                </h2>

                {grants.length === 0 ? (
                    <PageState
                        state="empty"
                        title="No MCP connections yet"
                        description="Create a connection above, then complete the OAuth authorization from your MCP client."
                    />
                ) : (
                    <ul className="grid gap-[10px]" data-test="mcp-grant-list">
                        {grants.map((grant) => (
                            <li
                                key={grant.id}
                                data-test="mcp-grant-row"
                                className="rounded-[10px] border p-[12px]"
                                style={{
                                    backgroundColor: 'var(--ck-surface)',
                                    borderColor: 'var(--ck-border)',
                                }}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-[10px]">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-[8px]">
                                            <span
                                                className="text-[13px] font-bold"
                                                style={{ color: 'var(--ck-text)' }}
                                            >
                                                {grant.displayName}
                                            </span>
                                            <span
                                                data-test="mcp-grant-state"
                                                className="rounded-[6px] border px-[6px] py-[2px] text-[10.5px] font-semibold"
                                                style={{
                                                    borderColor: stateColor(grant.state),
                                                    color: stateColor(grant.state),
                                                }}
                                            >
                                                {stateLabel(grant.state)}
                                            </span>
                                        </div>
                                        <div
                                            className="mt-[2px] font-mono text-[10.5px]"
                                            style={{ color: 'var(--ck-text-3)' }}
                                        >
                                            {grant.oauthClientId}
                                        </div>
                                        <div className="mt-[4px] flex flex-wrap gap-[6px]">
                                            {grant.scopes.map((scope) => (
                                                <span
                                                    key={scope}
                                                    className="rounded-[6px] border px-[6px] py-[2px] font-mono text-[10.5px]"
                                                    style={{
                                                        borderColor: 'var(--ck-border)',
                                                        color: 'var(--ck-text-2)',
                                                    }}
                                                >
                                                    {scope}
                                                </span>
                                            ))}
                                        </div>
                                        <div
                                            className="mt-[4px] text-[11px]"
                                            style={{ color: 'var(--ck-text-2)' }}
                                        >
                                            Created {grant.createdAt ?? 'unknown'}
                                            {grant.expiresAt
                                                ? ` · Expires ${grant.expiresAt}`
                                                : ' · Never expires'}
                                            {grant.lastUsedAt
                                                ? ` · Last used ${grant.lastUsedAt}`
                                                : ' · Never used'}
                                        </div>
                                    </div>
                                    {grant.state === 'active' && (
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            size="sm"
                                            onClick={() => revoke(grant)}
                                            data-test="revoke-mcp-grant-button"
                                        >
                                            Revoke
                                        </Button>
                                    )}
                                </div>

                                {grant.recentCalls.length > 0 && (
                                    <div className="mt-[10px] border-t pt-[10px]" style={{ borderColor: 'var(--ck-border)' }}>
                                        <div
                                            className="mb-[6px] text-[11px] font-semibold"
                                            style={{ color: 'var(--ck-text-2)' }}
                                        >
                                            Recent calls
                                        </div>
                                        <ul className="grid gap-[4px]" data-test="mcp-grant-recent-calls">
                                            {grant.recentCalls.map((call) => (
                                                <li
                                                    key={call.id}
                                                    className="flex flex-wrap items-center gap-[8px] text-[11px]"
                                                    style={{ color: 'var(--ck-text-2)' }}
                                                >
                                                    <span
                                                        className="font-mono"
                                                        style={{ color: 'var(--ck-text)' }}
                                                    >
                                                        {call.subjectType}:{call.subjectName}
                                                    </span>
                                                    <span
                                                        style={{
                                                            color:
                                                                call.outcome === 'allowed'
                                                                    ? 'var(--ck-success)'
                                                                    : 'var(--ck-danger)',
                                                        }}
                                                    >
                                                        {call.outcome}
                                                    </span>
                                                    <span>{call.durationMs}ms</span>
                                                    <span>{call.createdAt ?? ''}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </AppShell>
    );
}
