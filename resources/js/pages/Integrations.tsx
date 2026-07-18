import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { StatusBadge } from '@/components/craftkeeper/StatusBadge';
import type { StatusBadgeStatus } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { AppShell } from '@/layouts/AppShell';
import type { IntegrationsPageProps, IntegrationStatusDTO } from '@/types/integrations';

/**
 * Task 19's Integrations overview: all ten integrations (Minecraft
 * directory, RCON, AI, CraftKeeper Catalog, Hangar, Modrinth, official
 * documentation cache, API, MCP, Umami) with a Connected/Disabled/
 * Degraded/Misconfigured state each — computed server-side by
 * App\Support\IntegrationHealthChecker, never guessed client-side. Every
 * row's state is shown via StatusBadge (color + a distinct shape glyph +
 * a text label — never color alone) and, when available, an honest
 * reason string. Every row also gets an actionable "Test" control that
 * POSTs to /integrations/test/{key}, which either performs a real,
 * on-demand live probe (RCON, the three catalog sources) or simply
 * re-renders the current state (everything else has no live network
 * check to trigger — see IntegrationController's own docblock).
 */
export default function Integrations({ integrations }: IntegrationsPageProps) {
    const [testing, setTesting] = useState<string | null>(null);

    function runTest(key: string) {
        setTesting(key);
        router.post(
            `/integrations/test/${key}`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setTesting(null),
            },
        );
    }

    return (
        <AppShell>
            <Head title="Integrations" />

            <header className="mb-[18px]">
                <h1 className="text-[20px] font-bold" style={{ color: 'var(--ck-text)' }}>
                    Integrations
                </h1>
                <p className="mt-[3px] text-[12.5px]" style={{ color: 'var(--ck-text-2)' }}>
                    Every external and optional connection CraftKeeper knows about, with its
                    real current state. Nothing here is fabricated — an integration that has
                    never been checked shows as disabled, never as a guessed &quot;connected&quot;.
                </p>
            </header>

            <ul className="grid gap-[12px] sm:grid-cols-2 lg:grid-cols-3" data-test="integration-list">
                {integrations.map((integration) => (
                    <IntegrationCard
                        key={integration.key}
                        integration={integration}
                        testing={testing === integration.key}
                        onTest={() => runTest(integration.key)}
                    />
                ))}
            </ul>
        </AppShell>
    );
}

function IntegrationCard({
    integration,
    testing,
    onTest,
}: {
    integration: IntegrationStatusDTO;
    testing: boolean;
    onTest: () => void;
}) {
    return (
        <li
            data-test="integration-row"
            data-integration-key={integration.key}
            className="flex flex-col gap-[10px] rounded-[12px] border p-[16px]"
            style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
        >
            <div className="flex items-start justify-between gap-[10px]">
                <span className="text-[13.5px] font-bold" style={{ color: 'var(--ck-text)' }}>
                    {integration.label}
                </span>
                <span data-test="integration-state">
                    <StatusBadge status={integration.state as StatusBadgeStatus} />
                </span>
            </div>

            {integration.reason && (
                <p className="text-[12px]" style={{ color: 'var(--ck-text-2)' }}>
                    {integration.reason}
                </p>
            )}

            {integration.testable && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={testing}
                        onClick={onTest}
                        data-test="integration-test-button"
                    >
                        {testing ? 'Testing…' : 'Test'}
                    </Button>
                </div>
            )}
        </li>
    );
}
