import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import OnboardingController from '@/actions/App/Http/Controllers/OnboardingController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import onboarding from '@/routes/onboarding';

/**
 * CraftKeeper's first-run setup wizard. `step` comes from the server and
 * reflects `InstallationState`/auth reality (see routes/web.php and
 * OnboardingController); the only *client-only* step is the welcome
 * screen's transition into the admin-account form, since nothing has been
 * persisted yet at that point and there is nothing for the server to
 * gate.
 *
 * Only the admin-account step is functional. The remaining steps persist
 * what's entered (so later tasks have something to build on) but don't
 * perform any live filesystem/RCON/AI/analytics calls yet — see the
 * class docblock on OnboardingController.
 */
type Step = 'welcome' | 'server' | 'rcon' | 'ai' | 'analytics' | 'complete';
type Phase = Step | 'admin';

type Props = {
    step: Step;
    passwordRules?: string;
    minecraftPath?: string | null;
    rconHost?: string | null;
    rconPort?: string | null;
    rconPasswordConfigured?: boolean;
    aiProvider?: string | null;
    aiApiKeyConfigured?: boolean;
    analyticsEnabled?: boolean;
};

const PHASE_ORDER: Phase[] = [
    'welcome',
    'admin',
    'server',
    'rcon',
    'ai',
    'analytics',
    'complete',
];

const PHASE_META: Record<Phase, { title: string; description: string }> = {
    welcome: {
        title: 'Welcome to CraftKeeper',
        description:
            "Let's get your server management console set up. This will only take a few minutes.",
    },
    admin: {
        title: 'Create your administrator account',
        description:
            'CraftKeeper is single-admin: this is the only account that will ever exist on this install.',
    },
    server: {
        title: 'Minecraft server directory',
        description:
            "Tell CraftKeeper where your Minecraft server files live. You can change this later — it's just saved for now.",
    },
    rcon: {
        title: 'RCON setup',
        description:
            'RCON lets CraftKeeper send commands to your running server.',
    },
    ai: {
        title: 'AI provider (optional)',
        description:
            'Connect an AI provider to enable assistant features later. You can skip this and add it any time.',
    },
    analytics: {
        title: 'Analytics (optional)',
        description:
            'Help improve CraftKeeper by sharing anonymous usage data. Entirely optional.',
    },
    complete: {
        title: "You're all set",
        description: 'CraftKeeper is ready to use.',
    },
};

export default function OnboardingIndex(props: Props) {
    const [showAdminForm, setShowAdminForm] = useState(false);

    const phase: Phase =
        props.step === 'welcome' && showAdminForm ? 'admin' : props.step;

    const meta = PHASE_META[phase];
    const stepNumber = PHASE_ORDER.indexOf(phase) + 1;

    setLayoutProps({
        title: meta.title,
        description: meta.description,
    });

    return (
        <>
            <Head title="Set up CraftKeeper" />

            <div className="mb-2 text-center font-mono text-xs font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                Step {stepNumber} of {PHASE_ORDER.length}
            </div>

            {phase === 'welcome' && (
                <WelcomeStep onContinue={() => setShowAdminForm(true)} />
            )}
            {phase === 'admin' && (
                <AdminStep passwordRules={props.passwordRules ?? ''} />
            )}
            {phase === 'server' && (
                <ServerStep minecraftPath={props.minecraftPath} />
            )}
            {phase === 'rcon' && (
                <RconStep
                    rconHost={props.rconHost}
                    rconPort={props.rconPort}
                    rconPasswordConfigured={props.rconPasswordConfigured ?? false}
                />
            )}
            {phase === 'ai' && (
                <AiStep
                    aiProvider={props.aiProvider}
                    aiApiKeyConfigured={props.aiApiKeyConfigured ?? false}
                />
            )}
            {phase === 'analytics' && (
                <AnalyticsStep
                    analyticsEnabled={props.analyticsEnabled ?? false}
                />
            )}
            {phase === 'complete' && <CompleteStep />}
        </>
    );
}

function WelcomeStep({ onContinue }: { onContinue: () => void }) {
    return (
        <div className="flex flex-col gap-6">
            <p className="text-sm text-muted-foreground">
                You'll create your administrator account, then walk through a
                few optional steps to point CraftKeeper at your Minecraft
                server. Every optional step can be skipped and configured
                later.
            </p>
            <Button type="button" className="w-full" onClick={onContinue}>
                Get started
            </Button>
        </div>
    );
}

function AdminStep({ passwordRules }: { passwordRules: string }) {
    return (
        <Form
            {...OnboardingController.storeAdmin.form()}
            resetOnError={['password', 'password_confirmation']}
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            type="text"
                            name="name"
                            required
                            autoFocus
                            autoComplete="name"
                            placeholder="Full name"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            required
                            autoComplete="email"
                            placeholder="email@example.com"
                        />
                        <InputError message={errors.email} />
                        <p className="text-xs text-muted-foreground">
                            Used as your login. CraftKeeper doesn't send mail
                            in this version, so nothing is sent to this
                            address.
                        </p>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            required
                            autoComplete="new-password"
                            placeholder="Password"
                            passwordrules={passwordRules}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            Confirm password
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            required
                            autoComplete="new-password"
                            placeholder="Confirm password"
                            passwordrules={passwordRules}
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <Button
                        type="submit"
                        className="w-full"
                        disabled={processing}
                        data-test="onboarding-admin-button"
                    >
                        {processing && <Spinner />}
                        Create administrator account
                    </Button>
                </div>
            )}
        </Form>
    );
}

function ServerStep({ minecraftPath }: { minecraftPath?: string | null }) {
    return (
        <Form
            {...OnboardingController.storeServer.form()}
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="minecraft_path">
                            Minecraft server directory
                        </Label>
                        <Input
                            id="minecraft_path"
                            type="text"
                            name="minecraft_path"
                            autoFocus
                            defaultValue={minecraftPath ?? ''}
                            placeholder="/srv/minecraft"
                        />
                        <InputError message={errors.minecraft_path} />
                        <p className="text-xs text-muted-foreground">
                            CraftKeeper will use this path once server
                            management is wired up in a later update. You can
                            leave this blank and set it later.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Save &amp; continue
                        </Button>
                    </div>

                    <SkipLink href={onboarding.rcon.url()} />
                </div>
            )}
        </Form>
    );
}

function RconStep({
    rconHost,
    rconPort,
    rconPasswordConfigured,
}: {
    rconHost?: string | null;
    rconPort?: string | null;
    rconPasswordConfigured: boolean;
}) {
    return (
        <Form
            {...OnboardingController.storeRcon.form()}
            resetOnError={['rcon_password']}
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <div className="grid gap-6">
                    <div className="rounded-md border bg-muted/50 p-4 text-xs text-muted-foreground">
                        <p className="font-medium text-foreground">
                            Before you continue, on your Minecraft server:
                        </p>
                        <ul className="mt-2 list-disc space-y-1 pl-4">
                            <li>
                                Set <code>enable-rcon=true</code> in{' '}
                                <code>server.properties</code>.
                            </li>
                            <li>
                                Choose a long, unique{' '}
                                <code>rcon.password</code> — don't reuse
                                another password.
                            </li>
                            <li>
                                Keep the RCON port firewalled/private. Never
                                expose it to the public internet.
                            </li>
                        </ul>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="rcon_host">RCON host</Label>
                        <Input
                            id="rcon_host"
                            type="text"
                            name="rcon_host"
                            autoFocus
                            defaultValue={rconHost ?? '127.0.0.1'}
                            placeholder="127.0.0.1"
                        />
                        <InputError message={errors.rcon_host} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="rcon_port">RCON port</Label>
                        <Input
                            id="rcon_port"
                            type="number"
                            name="rcon_port"
                            min={1}
                            max={65535}
                            defaultValue={rconPort ?? '25575'}
                            placeholder="25575"
                        />
                        <InputError message={errors.rcon_port} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="rcon_password">RCON password</Label>
                        <PasswordInput
                            id="rcon_password"
                            name="rcon_password"
                            autoComplete="off"
                            placeholder={
                                rconPasswordConfigured
                                    ? 'Leave blank to keep the saved password'
                                    : 'RCON password'
                            }
                        />
                        <InputError message={errors.rcon_password} />
                        {rconPasswordConfigured && (
                            <p className="text-xs text-muted-foreground">
                                A password is already saved for RCON. It's
                                never shown here again — leave this blank to
                                keep it.
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            disabled
                            title="Connection testing lands with real RCON support in a later update."
                        >
                            Test connection (coming soon)
                        </Button>
                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Save &amp; continue
                        </Button>
                    </div>

                    <SkipLink href={onboarding.ai.url()} />
                </div>
            )}
        </Form>
    );
}

function AiStep({
    aiProvider,
    aiApiKeyConfigured,
}: {
    aiProvider?: string | null;
    aiApiKeyConfigured: boolean;
}) {
    return (
        <Form
            {...OnboardingController.storeAi.form()}
            resetOnError={['ai_api_key']}
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="ai_provider">AI provider</Label>
                        <Input
                            id="ai_provider"
                            type="text"
                            name="ai_provider"
                            autoFocus
                            defaultValue={aiProvider ?? ''}
                            placeholder="e.g. openai, anthropic"
                        />
                        <InputError message={errors.ai_provider} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="ai_api_key">API key</Label>
                        <PasswordInput
                            id="ai_api_key"
                            name="ai_api_key"
                            autoComplete="off"
                            placeholder={
                                aiApiKeyConfigured
                                    ? 'Leave blank to keep the saved key'
                                    : 'API key'
                            }
                        />
                        <InputError message={errors.ai_api_key} />
                        {aiApiKeyConfigured && (
                            <p className="text-xs text-muted-foreground">
                                A key is already saved. It's never shown here
                                again — leave this blank to keep it.
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            AI features aren't wired up yet in this version —
                            this is saved for when they land.
                        </p>
                    </div>

                    <Button
                        type="submit"
                        className="w-full"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Save &amp; continue
                    </Button>

                    <SkipLink href={onboarding.analytics.url()} />
                </div>
            )}
        </Form>
    );
}

function AnalyticsStep({
    analyticsEnabled,
}: {
    analyticsEnabled: boolean;
}) {
    return (
        <Form
            {...OnboardingController.storeAnalytics.form()}
            className="flex flex-col gap-6"
        >
            {({ processing }) => (
                <div className="grid gap-6">
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="analytics_enabled"
                            name="analytics_enabled"
                            defaultChecked={analyticsEnabled}
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="analytics_enabled">
                                Share anonymous usage analytics
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                No personal data or server contents — just
                                anonymous usage counts. Off by default, and
                                you can change this later.
                            </p>
                        </div>
                    </div>

                    <Button
                        type="submit"
                        className="w-full"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Save &amp; continue
                    </Button>

                    <SkipLink href={onboarding.complete.url()} />
                </div>
            )}
        </Form>
    );
}

function CompleteStep() {
    return (
        <div className="flex flex-col gap-6">
            <p className="text-sm text-muted-foreground">
                Your administrator account is set up and CraftKeeper is
                ready. You can revisit the Minecraft directory, RCON, AI, and
                analytics settings any time.
            </p>
            <Button asChild className="w-full">
                <Link href={dashboard().url}>Go to dashboard</Link>
            </Button>
        </div>
    );
}

function SkipLink({ href }: { href: string }) {
    return (
        <div className="text-center text-sm text-muted-foreground">
            <Link href={href} className="underline underline-offset-4">
                Skip for now
            </Link>
        </div>
    );
}
