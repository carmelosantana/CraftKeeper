import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { bootEcho } from '@/lib/echo';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Configuring Echo once, at boot, is required before any page's
// useEcho()/useConnectionStatus() call runs — see resources/js/lib/echo.ts.
bootEcho();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'DesignSystem':
            case name === 'Overview':
            case name === 'Activity':
            case name.startsWith('config/'):
            case name.startsWith('server/'):
            case name.startsWith('plugins/'):
                // config/*, server/*, plugins/*, Overview, and Activity all
                // wrap themselves in the CraftKeeper AppShell (Task 3)
                // directly, the same way DesignSystem does — the starter
                // kit's own AppLayout below is a different, pre-CraftKeeper
                // sidebar shell that these pages must not be double-wrapped
                // in.
                return null;
            case name.startsWith('auth/'):
            case name.startsWith('onboarding/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
