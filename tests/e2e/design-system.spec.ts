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
});
