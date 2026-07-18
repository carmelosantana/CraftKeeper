import { Form, Head } from '@inertiajs/react';
import SettingsController from '@/actions/App/Http/Controllers/SettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AiSettingsPageProps } from '@/types/settings';

/**
 * Task 19's AI Providers settings section: the hosted (OpenAI-compatible)
 * and Ollama provider fields App\Models\AiProviderConfiguration reads —
 * onboarding (Task 16) only ever collects a bare provider name and an
 * API key; the base URL/model fields have had no dedicated settings UI
 * until this task. The API key field is always blank on load, exactly
 * like the RCON password field on the Server settings page — never
 * re-rendered once stored.
 */
export default function AiSettings({
    provider,
    hostedBaseUrl,
    hostedModel,
    hostedApiKeyConfigured,
    ollamaBaseUrl,
    ollamaModel,
    ollamaAllowUnredacted,
}: AiSettingsPageProps) {
    return (
        <>
            <Head title="AI provider settings" />

            <h1 className="sr-only">AI provider settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="AI Providers"
                    description="Optional AI assistant provider and credentials. Leave the provider blank to keep AI disabled."
                />

                <Form
                    {...SettingsController.updateAi.form()}
                    options={{ preserveScroll: true }}
                    resetOnError={['hosted_api_key']}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="provider">Active provider</Label>
                                <Input
                                    id="provider"
                                    name="provider"
                                    defaultValue={provider ?? ''}
                                    placeholder='"ollama", or any other value to use the hosted provider'
                                    maxLength={100}
                                    data-test="ai-provider-input"
                                />
                                <InputError message={errors.provider} />
                            </div>

                            <fieldset className="grid gap-2 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">Hosted (OpenAI-compatible)</legend>
                                <div className="grid gap-2">
                                    <Label htmlFor="hosted_base_url">Base URL</Label>
                                    <Input
                                        id="hosted_base_url"
                                        name="hosted_base_url"
                                        defaultValue={hostedBaseUrl ?? ''}
                                        placeholder="https://api.openai.com/v1"
                                        maxLength={2048}
                                        data-test="ai-hosted-base-url-input"
                                    />
                                    <InputError message={errors.hosted_base_url} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="hosted_model">Model</Label>
                                    <Input
                                        id="hosted_model"
                                        name="hosted_model"
                                        defaultValue={hostedModel ?? ''}
                                        placeholder="gpt-4o-mini"
                                        maxLength={255}
                                        data-test="ai-hosted-model-input"
                                    />
                                    <InputError message={errors.hosted_model} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="hosted_api_key">
                                        API key{' '}
                                        {hostedApiKeyConfigured && (
                                            <span className="text-muted-foreground font-normal">
                                                (a key is already on file — leave blank to keep it)
                                            </span>
                                        )}
                                    </Label>
                                    <Input
                                        id="hosted_api_key"
                                        name="hosted_api_key"
                                        type="password"
                                        autoComplete="off"
                                        placeholder={hostedApiKeyConfigured ? '••••••••' : ''}
                                        maxLength={1024}
                                        data-test="ai-hosted-api-key-input"
                                    />
                                    <InputError message={errors.hosted_api_key} />
                                </div>
                            </fieldset>

                            <fieldset className="grid gap-2 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">Ollama (no API key needed)</legend>
                                <div className="grid gap-2">
                                    <Label htmlFor="ollama_base_url">Base URL</Label>
                                    <Input
                                        id="ollama_base_url"
                                        name="ollama_base_url"
                                        defaultValue={ollamaBaseUrl ?? ''}
                                        placeholder="http://ollama:11434/v1"
                                        maxLength={2048}
                                        data-test="ai-ollama-base-url-input"
                                    />
                                    <InputError message={errors.ollama_base_url} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="ollama_model">Model</Label>
                                    <Input
                                        id="ollama_model"
                                        name="ollama_model"
                                        defaultValue={ollamaModel ?? ''}
                                        placeholder="llama3.2"
                                        maxLength={255}
                                        data-test="ai-ollama-model-input"
                                    />
                                    <InputError message={errors.ollama_model} />
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        id="ollama_allow_unredacted"
                                        name="ollama_allow_unredacted"
                                        // See settings/analytics.tsx's
                                        // "enabled" checkbox for why this
                                        // explicit value is required.
                                        value="1"
                                        defaultChecked={ollamaAllowUnredacted}
                                        data-test="ai-ollama-allow-unredacted-checkbox"
                                    />
                                    Allow sending unredacted context to this LOCAL Ollama instance
                                </label>
                            </fieldset>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing} data-test="update-ai-button">
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

AiSettings.layout = {
    breadcrumbs: [{ title: 'AI provider settings', href: '/settings/ai' }],
};
