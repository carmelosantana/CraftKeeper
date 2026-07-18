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

    test('exposes status without relying on color (StatusBadge, live DOM)', async ({
        page,
    }) => {
        await page.goto('/design-system');

        const degraded = page.getByRole('status', { name: /degraded/i });

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

    // Task 20's ambiguity resolution #1: a systematic, computed (not
    // trusted-from-the-handoff-doc) re-verification of the four AA
    // contrast failures that had each accumulated a per-task workaround
    // instead of a source fix — ck-text-3, the StatusBadge "danger" chip,
    // the destructive Button, and the Sonner toast description. This
    // reads the real `--ck-*` custom properties straight off the live
    // page (not hardcoded expectations) and computes WCAG 2.2 contrast
    // in-browser, so a future token change that regresses any of these
    // pairs fails this suite rather than silently shipping.
    test('token contrast: ck-text-3 and the StatusBadge danger chip clear 4.5:1 AA in both themes', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/design-system');
        await expect(
            page.getByRole('heading', { name: 'Design system', level: 1 }),
        ).toBeVisible();

        const readTokenContrasts = () =>
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

                function mix(
                    hex1: string,
                    hex2: string,
                    pct1: number,
                ): string {
                    const a = normalizeHex(hex1).match(
                        /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i,
                    )!;
                    const b = normalizeHex(hex2).match(
                        /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i,
                    )!;
                    const p = pct1 / 100;
                    const chan = (x: string, y: string) =>
                        Math.round(
                            parseInt(x, 16) * p + parseInt(y, 16) * (1 - p),
                        );

                    return (
                        '#' +
                        [1, 2, 3]
                            .map((i) =>
                                chan(a[i], b[i]).toString(16).padStart(2, '0'),
                            )
                            .join('')
                    );
                }

                const root = getComputedStyle(document.documentElement);
                const token = (name: string) =>
                    root.getPropertyValue(name).trim();

                const surface = token('--ck-surface');
                const elevated = token('--ck-elevated');
                const text3 = token('--ck-text-3');
                const danger = token('--ck-danger');
                const dangerFg = token('--ck-danger-fg');

                // Mirrors resources/js/lib/ck-tokens.ts's ckChipStyle():
                // the chip background is a 5% tint of the tone color over
                // whatever surface sits behind it.
                const dangerChipOnSurface = mix(danger, surface, 5);
                const dangerChipOnElevated = mix(danger, elevated, 5);

                return {
                    text3VsSurface: contrastRatio(text3, surface),
                    text3VsElevated: contrastRatio(text3, elevated),
                    dangerChipVsSurface: contrastRatio(
                        danger,
                        dangerChipOnSurface,
                    ),
                    dangerChipVsElevated: contrastRatio(
                        danger,
                        dangerChipOnElevated,
                    ),
                    dangerFgVsDanger: contrastRatio(dangerFg, danger),
                };
            });

        const dark = await readTokenContrasts();
        expect(dark.text3VsSurface).toBeGreaterThanOrEqual(4.5);
        expect(dark.text3VsElevated).toBeGreaterThanOrEqual(4.5);
        expect(dark.dangerChipVsSurface).toBeGreaterThanOrEqual(4.5);
        expect(dark.dangerFgVsDanger).toBeGreaterThanOrEqual(4.5);

        await page.getByRole('button', { name: 'light', exact: true }).first().click();
        await expect(page.locator('html')).toHaveAttribute(
            'data-theme',
            'light',
        );

        const light = await readTokenContrasts();
        expect(light.text3VsSurface).toBeGreaterThanOrEqual(4.5);
        expect(light.text3VsElevated).toBeGreaterThanOrEqual(4.5);
        expect(light.dangerChipVsSurface).toBeGreaterThanOrEqual(4.5);
        expect(light.dangerFgVsDanger).toBeGreaterThanOrEqual(4.5);
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
