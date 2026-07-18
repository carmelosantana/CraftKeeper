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

const readDocumentCkTheme = (): CkThemeName => {
    if (typeof document === 'undefined') {
        return 'dark';
    }

    // Mirrors resources/css/app.css's own fallback: `:root, [data-theme='dark']`
    // is the dark-theme selector, so anything other than the literal
    // string 'light' resolves to dark — including the attribute being
    // absent entirely (e.g. a layout that never renders AppShell/
    // CkThemeProvider at all).
    return document.documentElement.getAttribute('data-theme') === 'light'
        ? 'light'
        : 'dark';
};

/**
 * Resolves the active CraftKeeper design-system theme by reading the
 * `data-theme` attribute directly off `<html>`, rather than through
 * `useCkTheme()`'s React context.
 *
 * Task 20: this exists because the app-root `<Toaster />` (resources/js/
 * app.tsx) is mounted as a SIBLING of the page tree in `withApp`, not a
 * descendant of whichever page's AppShell renders `CkThemeProvider` — so
 * it cannot call `useCkTheme()` at all (no context to read; every page
 * mounts/unmounts its own provider instance). Before this hook existed,
 * `resources/js/components/ui/sonner.tsx` passed Sonner's `theme` prop
 * from `useAppearance()` instead — the OLDER, UNRELATED starter-kit
 * light/dark/system toggle (`resources/js/hooks/use-appearance.tsx`),
 * which has nothing to do with the CraftKeeper theme picker and can (and
 * in practice does) disagree with it. Sonner keys several of its OWN
 * hardcoded sub-element colors (`[data-description]`, `[data-cancel]`,
 * dark-mode `[data-close-button]`) off its internal `data-sonner-theme`
 * attribute, while this app overrides the toast's own background/text
 * via `--normal-bg`/`--normal-text` (see sonner.tsx) to track the
 * CraftKeeper theme instead. When the two disagreed, Sonner's hardcoded
 * description color (chosen to read against ITS OWN theme's background)
 * ended up painted on top of OUR (differently-themed) background —
 * measured as low as ~1.2:1 in the reproduced mismatch, comfortably
 * explaining the ~1.88:1 axe violation flagged in Task 19's e2e run
 * (`li[data-sonner-toast]`, description text). Reading `data-theme`
 * directly (the same attribute `CkThemeProvider` duplicates onto
 * `<html>` specifically so portaled/provider-less content can react to
 * it — see that provider's own docblock) keeps Sonner's internal theme
 * flag permanently in lockstep with the CraftKeeper theme that actually
 * drives the toast's background, eliminating the mismatch at its root
 * rather than patching one color pair. See docs/architecture/
 * decisions.md (Task 20) for the full before/after contrast numbers.
 */
export function useCkResolvedThemeFromDocument(): CkThemeName {
    const [theme, setTheme] = useState<CkThemeName>(readDocumentCkTheme);

    useEffect(() => {
        if (typeof document === 'undefined') {
            return;
        }

        const root = document.documentElement;
        const sync = () => setTheme(readDocumentCkTheme());

        sync();

        const observer = new MutationObserver(sync);
        observer.observe(root, {
            attributes: true,
            attributeFilter: ['data-theme'],
        });

        return () => observer.disconnect();
    }, []);

    return theme;
}
