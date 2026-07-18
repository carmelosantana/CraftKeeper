import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * Task 3 — design system: verifies the shared AppShell/token layer at the
 * three documented breakpoints (design-tokens.json `layout.breakpoints`
 * plus the desktop design width) never scrolls horizontally and has no
 * automated accessibility violations (WCAG 2.2 AA target, status never
 * color-only).
 */
const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 1000 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'mobile', width: 390, height: 844 },
] as const;

test.describe('design system', () => {
    for (const viewport of VIEWPORTS) {
        test(`renders /design-system with no horizontal scroll and no axe violations at ${viewport.name} (${viewport.width}x${viewport.height})`, async ({
            page,
        }) => {
            await page.setViewportSize({
                width: viewport.width,
                height: viewport.height,
            });
            await page.goto('/design-system');
            await expect(
                page.getByRole('heading', { name: 'Design system', level: 1 }),
            ).toBeVisible();

            const { scrollWidth, clientWidth } = await page.evaluate(() => ({
                scrollWidth: document.documentElement.scrollWidth,
                clientWidth: document.documentElement.clientWidth,
            }));

            expect(scrollWidth).toBeLessThanOrEqual(clientWidth);

            const results = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
                .analyze();

            expect(results.violations).toEqual([]);
        });
    }

    // Task 20 fix pass (Important 3): every axe scan above runs in the
    // default (dark) theme only — the light theme was never
    // axe-scanned, which is exactly why the light-theme chip/text-3 AA
    // failures (see docs/architecture/decisions.md's Task 20 fix-pass
    // entry) went undetected. This flips the theme after load (the same
    // action a real user takes) and re-scans, at the full desktop
    // viewport, with the same WCAG 2.2 AA tag set as every other scan in
    // this file.
    test('renders /design-system with no axe violations in the LIGHT theme', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/design-system');
        await expect(
            page.getByRole('heading', { name: 'Design system', level: 1 }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'light', exact: true }).first().click();
        await expect(page.locator('html')).toHaveAttribute(
            'data-theme',
            'light',
        );

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
            .analyze();

        expect(results.violations).toEqual([]);
    });

    test('exposes status without relying on color (StatusBadge, live DOM)', async ({
        page,
    }) => {
        await page.goto('/design-system');

        // Task 20 fix pass's chip-contrast fixture (see DesignSystem.tsx)
        // renders its own "degraded" StatusBadge instances alongside the
        // pre-existing "every documented state" gallery, so more than one
        // now matches — `.first()` picks the gallery's, any of them would
        // do since this only cares that "degraded" renders with a visible
        // label.
        const degraded = page
            .getByRole('status', { name: /degraded/i })
            .first();

        await expect(degraded).toBeVisible();
        await expect(degraded).toContainText('Degraded');
    });

    test('the command palette opens with ⌘K/Ctrl+K, traps focus, and closes on Escape', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/design-system');
        await expect(
            page.getByRole('heading', { name: 'Design system', level: 1 }),
        ).toBeVisible();

        await page.keyboard.press('ControlOrMeta+k');

        const dialog = page.getByRole('dialog');

        await expect(dialog).toBeVisible();
        await expect(
            page.getByPlaceholder('Search or run a command…'),
        ).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    });

    test('below 1024px the sidebar is hidden and the nav drawer opens from the mobile header', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/design-system');
        await expect(
            page.getByRole('heading', { name: 'Design system', level: 1 }),
        ).toBeVisible();

        await expect(
            page.getByRole('navigation', { name: 'Primary' }),
        ).toBeHidden();

        await page.getByRole('button', { name: 'Open navigation' }).click();

        const drawer = page.getByRole('dialog');

        await expect(drawer).toBeVisible();
        await expect(
            drawer.getByRole('listitem').filter({ hasText: 'Overview' }),
        ).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
    });

    test('has a skip-navigation link that moves focus to the main content', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/design-system');
        await expect(
            page.getByRole('heading', { name: 'Design system', level: 1 }),
        ).toBeVisible();

        await page.keyboard.press('Tab');
        await expect(
            page.getByRole('link', { name: 'Skip to content' }),
        ).toBeFocused();

        await page.keyboard.press('Enter');
        await expect(page.locator('#ck-main-content')).toBeFocused();
    });

    // Task 20's ambiguity resolution #1, fix pass: a systematic, computed
    // (not trusted-from-the-handoff-doc, not trusted from a prior pass's
    // own claims either) re-verification of ck-text-3 and every
    // StatusBadge/RestartRequired chip tone. The ORIGINAL version of this
    // test recomputed the chip's color-mix formula (background = mix(
    // tone, surface, <hardcoded 5>%)) INSIDE the test itself, so a
    // regression that reverted ckChipStyle's actual fill percentage in
    // resources/js/lib/ck-tokens.ts would still have passed — the test
    // was verifying its own hardcoded assumption, not the shipped
    // component. This version instead renders the REAL StatusBadge/
    // RestartRequired components (via DesignSystem.tsx's "Chip contrast
    // fixture", `[data-test="chip-contrast-bg"]` /
    // `[data-test="chip-contrast-surface"]` /
    // `[data-test="chip-contrast-elevated"]`) and reads
    // `getComputedStyle(el).backgroundColor`/`.color` straight off the
    // live DOM, compositing the chip's own (possibly translucent)
    // background over its real container's background — so it fails if
    // `ckChipStyle` ever regresses, at any fill percentage. Loops all
    // four tones (success/warning/danger/info) x all three real chip
    // surfaces (`--ck-bg` — e.g. AppShell.tsx's header RestartRequired
    // chip — `--ck-surface`, `--ck-elevated`) x both themes.
    test('token contrast: ck-text-3 and every StatusBadge/RestartRequired chip tone clear 4.5:1 AA on --ck-bg, --ck-surface, and --ck-elevated, in both themes', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/design-system');
        await expect(
            page.getByRole('heading', { name: 'Design system', level: 1 }),
        ).toBeVisible();

        const readTextTokenContrasts = () =>
            page.evaluate(() => {
                function srgbToLinear(c: number): number {
                    const n = c / 255;
                    return n <= 0.04045
                        ? n / 12.92
                        : ((n + 0.055) / 1.055) ** 2.4;
                }

                // The browser normalizes #ffffff-style values it serializes
                // back out of getComputedStyle() to the 3-digit shorthand
                // (e.g. "#fff") whenever every channel pair repeats, so
                // both digit lengths have to be accepted here.
                function normalizeHex(hex: string): string {
                    const trimmed = hex.trim();
                    const short = trimmed.match(
                        /^#?([0-9a-f])([0-9a-f])([0-9a-f])$/i,
                    );
                    if (short) {
                        return `#${short[1]}${short[1]}${short[2]}${short[2]}${short[3]}${short[3]}`;
                    }
                    const long = trimmed.match(/^#?([0-9a-f]{6})$/i);
                    if (long) {
                        return `#${long[1]}`;
                    }
                    throw new Error(`not a hex color: "${hex}"`);
                }

                function luminance(hex: string): number {
                    const int = parseInt(normalizeHex(hex).slice(1), 16);
                    return (
                        0.2126 * srgbToLinear((int >> 16) & 255) +
                        0.7152 * srgbToLinear((int >> 8) & 255) +
                        0.0722 * srgbToLinear(int & 255)
                    );
                }

                function contrastRatio(hex1: string, hex2: string): number {
                    const l1 = luminance(hex1);
                    const l2 = luminance(hex2);
                    const lighter = Math.max(l1, l2);
                    const darker = Math.min(l1, l2);
                    return (lighter + 0.05) / (darker + 0.05);
                }

                const root = getComputedStyle(document.documentElement);
                const token = (name: string) =>
                    root.getPropertyValue(name).trim();

                const surface = token('--ck-surface');
                const elevated = token('--ck-elevated');
                const text3 = token('--ck-text-3');
                const danger = token('--ck-danger');
                const dangerFg = token('--ck-danger-fg');

                return {
                    text3VsSurface: contrastRatio(text3, surface),
                    text3VsElevated: contrastRatio(text3, elevated),
                    dangerFgVsDanger: contrastRatio(dangerFg, danger),
                };
            });

        // Renders real StatusBadge/RestartRequired chips (DesignSystem.tsx's
        // fixture) and reads their ACTUAL computed background/text color
        // off the live DOM — not a re-implementation of ckChipStyle's
        // color-mix formula. The chip's own backgroundColor may itself be
        // translucent (an rgba() with alpha < 1, e.g. from a color-mix()
        // tint), so it's alpha-composited over its real container's
        // (opaque) backgroundColor before computing the ratio, exactly as
        // a browser paints it.
        const readChipContrasts = () =>
            page.evaluate(() => {
                function srgbToLinear(c: number): number {
                    const n = c / 255;
                    return n <= 0.04045
                        ? n / 12.92
                        : ((n + 0.055) / 1.055) ** 2.4;
                }

                function luminance(rgb: {
                    r: number;
                    g: number;
                    b: number;
                }): number {
                    return (
                        0.2126 * srgbToLinear(rgb.r) +
                        0.7152 * srgbToLinear(rgb.g) +
                        0.0722 * srgbToLinear(rgb.b)
                    );
                }

                function parseColor(value: string): {
                    r: number;
                    g: number;
                    b: number;
                    a: number;
                } {
                    const rgbMatch = value.match(
                        /rgba?\(\s*([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\s*\)/,
                    );
                    if (rgbMatch) {
                        return {
                            r: Number(rgbMatch[1]),
                            g: Number(rgbMatch[2]),
                            b: Number(rgbMatch[3]),
                            a: rgbMatch[4] === undefined ? 1 : Number(rgbMatch[4]),
                        };
                    }

                    // A `color-mix()` computed value doesn't always
                    // serialize back out as rgb()/rgba() — Chromium can
                    // report it as `color(srgb R G B / A)` (channels as
                    // 0-1 fractions, not 0-255 integers). Deliberately
                    // handled here (not just rgb()/rgba()) so that a
                    // regression which reintroduces a color-mix() chip
                    // fill still produces a real, readable "contrast
                    // ratio X < 4.5" assertion failure below, instead of
                    // an opaque "not a color" parse error that would
                    // mask the actual regression this guard exists to
                    // catch.
                    const colorFnMatch = value.match(
                        /color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+))?\s*\)/,
                    );
                    if (colorFnMatch) {
                        return {
                            r: Number(colorFnMatch[1]) * 255,
                            g: Number(colorFnMatch[2]) * 255,
                            b: Number(colorFnMatch[3]) * 255,
                            a:
                                colorFnMatch[4] === undefined
                                    ? 1
                                    : Number(colorFnMatch[4]),
                        };
                    }

                    throw new Error(
                        `not an rgb()/rgba()/color(srgb ...) color: "${value}"`,
                    );
                }

                function compositeOverOpaque(
                    fg: { r: number; g: number; b: number; a: number },
                    bg: { r: number; g: number; b: number },
                ): { r: number; g: number; b: number } {
                    return {
                        r: fg.r * fg.a + bg.r * (1 - fg.a),
                        g: fg.g * fg.a + bg.g * (1 - fg.a),
                        b: fg.b * fg.a + bg.b * (1 - fg.a),
                    };
                }

                function contrastRatio(
                    rgb1: { r: number; g: number; b: number },
                    rgb2: { r: number; g: number; b: number },
                ): number {
                    const l1 = luminance(rgb1);
                    const l2 = luminance(rgb2);
                    const lighter = Math.max(l1, l2);
                    const darker = Math.min(l1, l2);
                    return (lighter + 0.05) / (darker + 0.05);
                }

                const TONE_SELECTORS: Array<[string, string]> = [
                    ['success', '[data-ck-status="online"]'],
                    ['warning', '[data-ck-status="degraded"]'],
                    ['danger', '[data-ck-status="offline"]'],
                    ['info', '[data-ck-status="in-progress"]'],
                ];

                const results: Record<string, number> = {};

                for (const surfaceName of ['bg', 'surface', 'elevated']) {
                    const container = document.querySelector(
                        `[data-test="chip-contrast-${surfaceName}"]`,
                    );
                    if (!container) {
                        throw new Error(
                            `chip-contrast fixture not found for ${surfaceName}`,
                        );
                    }

                    const containerBg = parseColor(
                        getComputedStyle(container).backgroundColor,
                    );

                    for (const [tone, selector] of TONE_SELECTORS) {
                        const el = container.querySelector(selector);
                        if (!el) {
                            throw new Error(
                                `chip not found: ${selector} in ${surfaceName} fixture`,
                            );
                        }
                        const style = getComputedStyle(el);
                        const bg = parseColor(style.backgroundColor);
                        const text = parseColor(style.color);
                        const effectiveBg = compositeOverOpaque(
                            bg,
                            containerBg,
                        );
                        results[`${tone}_vs_${surfaceName}`] = contrastRatio(
                            text,
                            effectiveBg,
                        );
                    }

                    const restartEl = container.querySelector(
                        '[data-ck-restart-required="chip"]',
                    );
                    if (!restartEl) {
                        throw new Error(
                            `RestartRequired chip not found in ${surfaceName} fixture`,
                        );
                    }
                    const restartStyle = getComputedStyle(restartEl);
                    const restartBg = parseColor(
                        restartStyle.backgroundColor,
                    );
                    const restartText = parseColor(restartStyle.color);
                    const restartEffectiveBg = compositeOverOpaque(
                        restartBg,
                        containerBg,
                    );
                    results[`restartRequired_vs_${surfaceName}`] =
                        contrastRatio(restartText, restartEffectiveBg);
                }

                return results;
            });

        function assertAllPass(
            results: Record<string, number>,
            themeName: string,
        ) {
            const entries = Object.entries(results);
            expect(entries.length).toBeGreaterThan(0);
            for (const [key, ratio] of entries) {
                expect(
                    ratio,
                    `${themeName} theme: ${key} measured ${ratio.toFixed(2)}:1, need >= 4.5:1`,
                ).toBeGreaterThanOrEqual(4.5);
            }
        }

        const darkTokens = await readTextTokenContrasts();
        expect(darkTokens.text3VsSurface).toBeGreaterThanOrEqual(4.5);
        expect(darkTokens.text3VsElevated).toBeGreaterThanOrEqual(4.5);
        expect(darkTokens.dangerFgVsDanger).toBeGreaterThanOrEqual(4.5);

        const darkChips = await readChipContrasts();
        assertAllPass(darkChips, 'dark');

        await page.getByRole('button', { name: 'light', exact: true }).first().click();
        await expect(page.locator('html')).toHaveAttribute(
            'data-theme',
            'light',
        );

        const lightTokens = await readTextTokenContrasts();
        expect(lightTokens.text3VsSurface).toBeGreaterThanOrEqual(4.5);
        expect(lightTokens.text3VsElevated).toBeGreaterThanOrEqual(4.5);
        expect(lightTokens.dangerFgVsDanger).toBeGreaterThanOrEqual(4.5);

        const lightChips = await readChipContrasts();
        assertAllPass(lightChips, 'light');
    });

    // Task 20: reproduces the exact bug behind the ~1.88:1 axe violation
    // Task 19 found (Sonner's own internal `data-sonner-theme` used to be
    // wired to the unrelated starter-kit appearance toggle instead of the
    // CraftKeeper design-system theme — see useCkResolvedThemeFromDocument's
    // docblock in resources/js/hooks/use-ck-theme.tsx) by actually
    // flipping the CraftKeeper theme after page load (the same action a
    // real user takes) and firing a real toast WITH a description (the
    // command palette's "Restart server…" review flow), then computing
    // the description text's contrast against its real, live-rendered
    // background. Also satisfies the requirement that axe itself scans a
    // page state with a toast visible — Task 19's own spec deliberately
    // scanned axe BEFORE ever triggering a toast.
    test('the Sonner toast description clears AA contrast against its live background, in both themes, and axe is clean while it is visible', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/design-system');
        await expect(
            page.getByRole('heading', { name: 'Design system', level: 1 }),
        ).toBeVisible();

        const triggerReviewToast = async () => {
            await page.keyboard.press('ControlOrMeta+k');
            await expect(page.getByRole('dialog')).toBeVisible();
            await page.getByText('Restart server…').click();
            await expect(
                page.locator('[data-sonner-toast]').last(),
            ).toBeVisible();
            await expect(
                page.locator('[data-sonner-toast] [data-description]').last(),
            ).toBeVisible();
        };

        const readToastContrast = () =>
            page.evaluate(() => {
                function srgbToLinear(c: number): number {
                    const n = c / 255;
                    return n <= 0.04045
                        ? n / 12.92
                        : ((n + 0.055) / 1.055) ** 2.4;
                }

                function luminance(rgb: {
                    r: number;
                    g: number;
                    b: number;
                }): number {
                    return (
                        0.2126 * srgbToLinear(rgb.r) +
                        0.7152 * srgbToLinear(rgb.g) +
                        0.0722 * srgbToLinear(rgb.b)
                    );
                }

                function parseRgb(value: string): {
                    r: number;
                    g: number;
                    b: number;
                } {
                    const m = value.match(
                        /rgba?\((\d+),\s*(\d+),\s*(\d+)/,
                    );
                    if (!m) {
                        throw new Error(`not an rgb() color: "${value}"`);
                    }
                    return {
                        r: Number(m[1]),
                        g: Number(m[2]),
                        b: Number(m[3]),
                    };
                }

                function contrastRatio(c1: string, c2: string): number {
                    const l1 = luminance(parseRgb(c1));
                    const l2 = luminance(parseRgb(c2));
                    const lighter = Math.max(l1, l2);
                    const darker = Math.min(l1, l2);
                    return (lighter + 0.05) / (darker + 0.05);
                }

                const toasts = document.querySelectorAll(
                    '[data-sonner-toast]',
                );
                const toast = toasts[toasts.length - 1] as HTMLElement | undefined;
                if (!toast) {
                    throw new Error('no toast is currently rendered');
                }

                const description = toast.querySelector('[data-description]');
                if (!description) {
                    throw new Error('toast has no [data-description] node');
                }

                const toastBg = getComputedStyle(toast).backgroundColor;
                const descriptionColor =
                    getComputedStyle(description).color;

                return {
                    descriptionVsToastBg: contrastRatio(
                        descriptionColor,
                        toastBg,
                    ),
                };
            });

        await triggerReviewToast();
        const darkResult = await readToastContrast();
        expect(darkResult.descriptionVsToastBg).toBeGreaterThanOrEqual(4.5);

        // The command palette dialog itself is still unmounting its exit
        // animation for a couple hundred ms after the click that closed
        // it — waiting for it to fully leave the DOM avoids axe
        // transiently scanning a mid-transition frame (which is not a
        // real, user-perceivable state) rather than the toast itself.
        await expect(page.getByRole('dialog')).toHaveCount(0);

        // Axe must be clean with the toast actually on screen — Task
        // 19's spec ordered its own axe scan BEFORE the toast-triggering
        // action specifically so it never saw one.
        const axeWithToastVisible = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
            .analyze();
        expect(axeWithToastVisible.violations).toEqual([]);

        // Flip the CraftKeeper theme and fire a second toast — this is
        // exactly the scenario that used to desync Sonner's own internal
        // theme from the CraftKeeper theme (see this test's docblock).
        await page.getByRole('button', { name: 'light', exact: true }).first().click();
        await expect(page.locator('html')).toHaveAttribute(
            'data-theme',
            'light',
        );

        await triggerReviewToast();
        const lightResult = await readToastContrast();
        expect(lightResult.descriptionVsToastBg).toBeGreaterThanOrEqual(4.5);
    });
});
