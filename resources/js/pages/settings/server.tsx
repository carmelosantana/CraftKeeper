import { Form, Head } from '@inertiajs/react';
import SettingsController from '@/actions/App/Http/Controllers/SettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ServerSettingsPageProps } from '@/types/settings';

/**
 * Task 19's General/Server settings section: the Minecraft directory and
 * RCON connection details App\Http\Controllers\OnboardingController first
 * collects during setup, editable again afterward. The RCON password
 * field is deliberately always blank on load — App\Models\Secret never
 * re-renders a stored value — with `rconPasswordConfigured` shown instead
 * so the operator can tell whether one is already on file without ever
 * seeing it.
 */
export default function ServerSettings({
    minecraftPath,
    rconHost,
    rconPort,
    rconPasswordConfigured,
}: ServerSettingsPageProps) {
    return (
        <>
            <Head title="Server settings" />

            <h1 className="sr-only">Server settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="General / Server"
                    description="Minecraft directory and RCON connection."
                />

                <Form
                    {...SettingsController.updateServer.form()}
                    options={{ preserveScroll: true }}
                    resetOnError={['rcon_password']}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="minecraft_path">Minecraft directory</Label>
                                <Input
                                    id="minecraft_path"
                                    name="minecraft_path"
                                    defaultValue={minecraftPath ?? ''}
                                    placeholder="/minecraft"
                                    maxLength={1024}
                                    data-test="minecraft-path-input"
                                />
                                <InputError message={errors.minecraft_path} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="rcon_host">RCON host</Label>
                                <Input
                                    id="rcon_host"
                                    name="rcon_host"
                                    defaultValue={rconHost ?? ''}
                                    placeholder="127.0.0.1"
                                    maxLength={255}
                                    data-test="rcon-host-input"
                                />
                                <InputError message={errors.rcon_host} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="rcon_port">RCON port</Label>
                                <Input
                                    id="rcon_port"
                                    name="rcon_port"
                                    defaultValue={rconPort ?? ''}
                                    placeholder="25575"
                                    inputMode="numeric"
                                    data-test="rcon-port-input"
                                />
                                <InputError message={errors.rcon_port} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="rcon_password">
                                    RCON password{' '}
                                    {rconPasswordConfigured && (
                                        <span className="text-muted-foreground font-normal">
                                            (a password is already on file — leave blank to keep it)
                                        </span>
                                    )}
                                </Label>
                                <Input
                                    id="rcon_password"
                                    name="rcon_password"
                                    type="password"
                                    autoComplete="off"
                                    placeholder={rconPasswordConfigured ? '••••••••' : ''}
                                    maxLength={255}
                                    data-test="rcon-password-input"
                                />
                                <InputError message={errors.rcon_password} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing} data-test="update-server-button">
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

ServerSettings.layout = {
    breadcrumbs: [{ title: 'Server settings', href: '/settings/server' }],
};
