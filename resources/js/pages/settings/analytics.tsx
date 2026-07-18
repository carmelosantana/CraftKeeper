import { Form, Head } from '@inertiajs/react';
import SettingsController from '@/actions/App/Http/Controllers/SettingsController';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AnalyticsSettingsPageProps } from '@/types/settings';

/**
 * Task 19's Analytics settings section: the optional Umami tag
 * (App\Support\UmamiScript). Disabled by default and never rendered
 * anywhere until the operator BOTH turns it on AND supplies a valid
 * HTTPS script URL and a website id — `active` reflects that full
 * computed result (not just the raw `enabled` checkbox), so an operator
 * who ticks the box but leaves the URL blank sees "Inactive" here rather
 * than being misled into thinking analytics is already running.
 */
export default function AnalyticsSettings({
    enabled,
    scriptUrl,
    websiteId,
    active,
    allowedOrigin,
}: AnalyticsSettingsPageProps) {
    return (
        <>
            <Head title="Analytics settings" />

            <h1 className="sr-only">Analytics settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Analytics"
                    description="Optional, self-hosted Umami analytics. Disabled by default — CraftKeeper never proxies this script and a failed load never affects the application."
                />

                <div className="flex items-center gap-2 text-sm" data-test="analytics-active-indicator">
                    <StatusText status={active ? 'online' : 'unknown'} label={active ? 'Active' : 'Inactive'} />
                    {active && allowedOrigin && (
                        <span className="text-muted-foreground">on {allowedOrigin}</span>
                    )}
                </div>

                <Form
                    {...SettingsController.updateAnalytics.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    id="enabled"
                                    name="enabled"
                                    // Radix's native mirror input defaults
                                    // to value="on" (matching plain HTML
                                    // checkboxes) when checked — Laravel's
                                    // `boolean` validation rule only
                                    // accepts 1/0/true/false, not "on", so
                                    // an explicit value is required for
                                    // this native-form submission to
                                    // validate at all.
                                    value="1"
                                    defaultChecked={enabled}
                                    data-test="analytics-enabled-checkbox"
                                />
                                Enable Umami analytics
                            </label>
                            <InputError message={errors.enabled} />

                            <div className="grid gap-2">
                                <Label htmlFor="script_url">Script URL</Label>
                                <Input
                                    id="script_url"
                                    name="script_url"
                                    defaultValue={scriptUrl ?? ''}
                                    placeholder="https://analytics.example.com/script.js"
                                    maxLength={2048}
                                    data-test="analytics-script-url-input"
                                />
                                <InputError message={errors.script_url} />
                                <p className="text-muted-foreground text-xs">
                                    Must be an HTTPS URL, or the tag will never render.
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="website_id">Website ID</Label>
                                <Input
                                    id="website_id"
                                    name="website_id"
                                    defaultValue={websiteId ?? ''}
                                    placeholder="00000000-0000-0000-0000-000000000000"
                                    maxLength={255}
                                    data-test="analytics-website-id-input"
                                />
                                <InputError message={errors.website_id} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing} data-test="update-analytics-button">
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

AnalyticsSettings.layout = {
    breadcrumbs: [{ title: 'Analytics settings', href: '/settings/analytics' }],
};
