import { Head } from '@inertiajs/react';
import SettingsController from '@/actions/App/Http/Controllers/SettingsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import type { AdvancedSettingsPageProps } from '@/types/settings';

/**
 * Task 19's Advanced settings section: environment info and the
 * exportable, redacted support bundle (App\Support\SupportBundleService).
 * The bundle deliberately EXCLUDES secrets, API/MCP tokens, AI chat
 * content, config secret values, and full uploaded JARs — see that
 * class's own docblock — so it is always safe to hand to a third party.
 */
export default function AdvancedSettings({
    dataRoot,
    minecraftRoot,
    phpVersion,
    laravelVersion,
}: AdvancedSettingsPageProps) {
    return (
        <>
            <Head title="Advanced settings" />

            <h1 className="sr-only">Advanced settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Advanced"
                    description="Diagnostics and a redacted support bundle for troubleshooting."
                />

                <dl className="grid gap-2 text-sm" data-test="advanced-environment-info">
                    <EnvironmentRow label="Data directory" value={dataRoot} />
                    <EnvironmentRow label="Minecraft directory" value={minecraftRoot} />
                    <EnvironmentRow label="PHP version" value={phpVersion} />
                    <EnvironmentRow label="Laravel version" value={laravelVersion} />
                </dl>

                <div className="space-y-2">
                    <p className="text-muted-foreground text-sm">
                        Includes versions, health, permissions, redacted settings, sanitized
                        recent logs, and recent operation failures. Never includes secrets, API
                        or MCP tokens, AI chat content, or uploaded plugin JARs.
                    </p>
                    <a href={SettingsController.downloadSupportBundle.url()} data-test="download-support-bundle-link">
                        <Button type="button" data-test="download-support-bundle-button">
                            Download support bundle
                        </Button>
                    </a>
                </div>
            </div>
        </>
    );
}

function EnvironmentRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-wrap gap-2">
            <dt className="text-muted-foreground w-40 flex-none">{label}</dt>
            <dd className="min-w-0 font-mono break-all">{value}</dd>
        </div>
    );
}

AdvancedSettings.layout = {
    breadcrumbs: [{ title: 'Advanced settings', href: '/settings/advanced' }],
};
