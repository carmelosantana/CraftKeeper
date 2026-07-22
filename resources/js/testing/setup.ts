import { configure } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';

// The app's test-hook convention is `data-test="..."` throughout
// resources/js/pages and resources/js/features (see e.g. login.tsx's
// `data-test="login-button"` and playwright.config.ts's matching
// `testIdAttribute`), not Testing Library's own `data-testid` default —
// align `getByTestId()` here so Vitest/RTL and Playwright agree on one
// attribute instead of silently matching nothing.
configure({ testIdAttribute: 'data-test' });

// jsdom implements no layout, so `scrollIntoView` is simply absent — and
// Console's follow-the-tail effect calls it on every render that appends a
// line. Nothing here asserts on scrolling; this only stops an unimplemented
// browser API from failing renders that are testing something else.
if (typeof Element !== 'undefined' && !Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = () => {};
}

// jsdom ships no `window.matchMedia` at all, and `@/hooks/use-mobile` calls
// it at MODULE scope — so every page importing it (Console, and any page
// behind AppShell) throws on import, before a single test body runs. Report
// "not mobile", the same answer `use-mobile`'s own server snapshot gives,
// and back it with a real listener registry so `useSyncExternalStore`
// subscribes and unsubscribes against something coherent.
if (typeof window !== 'undefined' && !window.matchMedia) {
    window.matchMedia = (query: string): MediaQueryList => {
        const listeners = new Set<unknown>();

        const add = (listener: unknown) => void listeners.add(listener);
        const remove = (listener: unknown) => void listeners.delete(listener);

        return {
            media: query,
            matches: false,
            onchange: null,
            addEventListener: (_type: string, listener: unknown) =>
                add(listener),
            removeEventListener: (_type: string, listener: unknown) =>
                remove(listener),
            dispatchEvent: () => true,
            // Deprecated pre-`addEventListener` half of the interface, kept
            // because the type demands it and older libraries still reach
            // for it.
            addListener: add,
            removeListener: remove,
            // The registry is deliberately untyped and the shape asserted
            // through `unknown`: nothing dispatches a media change in this
            // suite, so the stub only has to satisfy subscribe/unsubscribe,
            // not model MediaQueryListEvent faithfully.
        } as unknown as MediaQueryList;
    };
}
