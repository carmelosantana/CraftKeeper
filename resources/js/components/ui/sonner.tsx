import { useFlashToast } from '@/hooks/use-flash-toast';
import { useCkResolvedThemeFromDocument } from '@/hooks/use-ck-theme';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    // Task 20: was `useAppearance()` (the unrelated starter-kit light/
    // dark/system toggle) — see useCkResolvedThemeFromDocument's own
    // docblock for the contrast bug this caused and why the CraftKeeper
    // design-system theme is read directly off `<html>` instead.
    const theme = useCkResolvedThemeFromDocument();

    useFlashToast();

    return (
        <Sonner
            theme={theme}
            className="toaster group"
            position="bottom-right"
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
