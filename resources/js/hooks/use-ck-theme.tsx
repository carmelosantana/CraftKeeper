import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

/**
 * CraftKeeper design-system theme state. Per `design-tokens.json` /
 * `Design/handoff/README.md`: "Every screen re-themes live from two axes —
 * theme: dark (default) | light; accent: terracotta (default) | emerald |
 * slate | bronze." This hook owns that live state and is written to
 * `data-theme` / `data-accent` on the AppShell root element; components
 * underneath read only the resulting `--ck-*` CSS variables.
 *
 * Persisting the choice (e.g. to a user's Appearance setting) is a later
 * task — this provider is intentionally uncontrolled/in-memory for now.
 */
export type CkThemeName = 'dark' | 'light';
export type CkAccentName = 'terracotta' | 'emerald' | 'slate' | 'bronze';

export const CK_ACCENTS: readonly CkAccentName[] = [
    'terracotta',
    'emerald',
    'slate',
    'bronze',
] as const;

interface CkThemeContextValue {
    theme: CkThemeName;
    accent: CkAccentName;
    setTheme: (theme: CkThemeName) => void;
    setAccent: (accent: CkAccentName) => void;
    toggleTheme: () => void;
}

const CkThemeContext = createContext<CkThemeContextValue | null>(null);

export function CkThemeProvider({
    children,
    defaultTheme = 'dark',
    defaultAccent = 'terracotta',
}: {
    children: ReactNode;
    defaultTheme?: CkThemeName;
    defaultAccent?: CkAccentName;
}) {
    const [theme, setTheme] = useState<CkThemeName>(defaultTheme);
    const [accent, setAccent] = useState<CkAccentName>(defaultAccent);

    // Radix primitives (Sheet/Dialog/DropdownMenu, used by the mobile nav
    // drawer, CommandPalette, and admin menu) render their content into a
    // portal appended to `document.body`, *outside* the AppShell's own DOM
    // subtree. CSS custom properties only cascade down the real DOM tree,
    // so `data-theme`/`data-accent` must also live on `<html>` for
    // portaled content to pick up the active theme/accent — the root
    // element's own attributes (set below in AppShell) cover the normal
    // subtree, this covers everything else.
    useEffect(() => {
        const root = document.documentElement;

        root.setAttribute('data-theme', theme);
        root.setAttribute('data-accent', accent);

        return () => {
            root.removeAttribute('data-theme');
            root.removeAttribute('data-accent');
        };
    }, [theme, accent]);

    const value = useMemo<CkThemeContextValue>(
        () => ({
            theme,
            accent,
            setTheme,
            setAccent,
            toggleTheme: () =>
                setTheme((current) => (current === 'dark' ? 'light' : 'dark')),
        }),
        [theme, accent],
    );

    return (
        <CkThemeContext.Provider value={value}>
            {children}
        </CkThemeContext.Provider>
    );
}

export function useCkTheme(): CkThemeContextValue {
    const context = useContext(CkThemeContext);

    if (!context) {
        throw new Error(
            'useCkTheme() must be called within a CkThemeProvider (rendered by AppShell).',
        );
    }

    return context;
}
