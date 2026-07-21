import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Task 16 — the optional AI assistant.
 *
 * No AI provider is ever configured in this e2e run (the onboarding flow
 * below explicitly skips the AI step, matching every other e2e spec in
 * this repo — see server-operations.spec.ts), so `/assistant`
 * deterministically renders the DISABLED state here — a genuinely
 * observed state, not a simulated one, exactly like this repo's existing
 * convention of never faking RCON/websocket state in e2e (see
 * server-operations.spec.ts's own docblock).
 *
 * The UNAVAILABLE state (a provider IS configured but its health check
 * fails) is deliberately NOT exercised here: reaching it for real would
 * require either a live unreachable network endpoint (flaky, and slow —
 * App\Ai\AiManager's health check has a genuine 2-second connect
 * timeout) or a test-only endpoint to seed Setting rows with no
 * corresponding product feature, which this task did not build. That
 * state — including the app staying healthy and the assistant page still
 * rendering 200 with "AI is unavailable" — is instead covered precisely
 * and deterministically by tests/Feature/Ai/AiUnavailableTest.php using a
 * real Fiber-driven agent turn against a Symfony MockHttpClient that
 * always throws, which is a strictly more precise test of that failure
 * mode than a real network timeout in a browser would be.
 */
const ADMIN_EMAIL = 'admin@craftkeeper.test';
const ADMIN_PASSWORD = 'a-very-long-unique-assistant-e2e-passphrase';

async function ensureLoggedInAdmin(page: Page): Promise<void> {
    const welcomeResponse = await page.goto('/onboarding');

    if (welcomeResponse?.status() === 404) {
        await page.goto('/login');
        await page.locator('#email').fill(ADMIN_EMAIL);
        await page.locator('#password').fill(ADMIN_PASSWORD);
        await page.getByTestId('login-button').click();
        await page.waitForURL('**/overview');

        return;
    }

    await page.getByRole('button', { name: 'Get started' }).click();
    await page.locator('#name').fill('Admin');
    await page.locator('#email').fill(ADMIN_EMAIL);
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('#password_confirmation').fill(ADMIN_PASSWORD);
    await page.getByTestId('onboarding-admin-button').click();

    await page.waitForURL('**/onboarding/server');
    await page.getByRole('link', { name: 'Skip for now' }).click();
    await page.waitForURL('**/onboarding/rcon');
    await page.getByRole('button', { name: 'Save & continue' }).click();
    await page.waitForURL('**/onboarding/ai');
    await page.getByRole('link', { name: 'Skip for now' }).click();
    await page.waitForURL('**/onboarding/analytics');
    await page.getByRole('link', { name: 'Skip for now' }).click();
    await page.waitForURL('**/onboarding/complete');
    await page.getByRole('link', { name: 'Go to CraftKeeper' }).click();
    await page.waitForURL('**/overview');
}

test.describe.serial('assistant', () => {
    let page: Page;

    test.beforeAll(async ({ browser, request }) => {
        const reset = await request.post('/__e2e__/reset');
        if (!reset.ok()) {
            throw new Error(
                `Failed to reset the e2e database before assistant.spec.ts (status ${reset.status()}). ` +
                    'Is E2E_TESTING=true set on the webServer process (see playwright.config.ts)?',
            );
        }

        const context = await browser.newContext();
        page = await context.newPage();
        await ensureLoggedInAdmin(page);
    });

    test.afterAll(async () => {
        await page.context().close();
    });

    test('requires authentication', async ({ request }) => {
        const response = await request.get('/assistant', {
            maxRedirects: 0,
        });
        expect(response.status()).toBe(302);
    });

    test('renders the assistant page in the disabled state when no AI provider is configured', async () => {
        const jsErrors: string[] = [];
        page.on('pageerror', (error) => jsErrors.push(error.message));

        await page.goto('/assistant');

        await expect(
            page.getByRole('heading', { name: 'Assistant', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByText('AI is disabled', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText(/no provider is configured/i),
        ).toBeVisible();

        // No "start a conversation" affordance is offered while disabled —
        // only the honest disabled-state banner.
        await expect(
            page.getByTestId('assistant-start-conversation'),
        ).toHaveCount(0);

        expect(jsErrors).toEqual([]);
    });

    test('is reachable from the primary navigation', async () => {
        await page.goto('/overview');
        await page.setViewportSize({ width: 1440, height: 1000 });
        // The primary nav's <Link> is given an explicit role="listitem"
        // (resources/js/layouts/AppShell.tsx's ShellNav), which some
        // browsers' accessibility trees do not expose the way a plain
        // ARIA "listitem" role normally would — matching on its visible
        // text within the "Primary sections" list is the reliable
        // selector here.
        await page
            .getByRole('list', { name: 'Primary sections' })
            .getByText('Assistant', { exact: true })
            .click();
        await page.waitForURL('**/assistant');
        await expect(
            page.getByRole('heading', { name: 'Assistant', level: 1 }),
        ).toBeVisible();
    });

    test('renders its desktop hierarchy with no horizontal scroll and is axe-clean', async () => {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto('/assistant');
        await expect(
            page.getByRole('heading', { name: 'Assistant', level: 1 }),
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

    test('renders at mobile width with no horizontal scroll and is axe-clean', async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/assistant');
        await expect(
            page.getByRole('heading', { name: 'Assistant', level: 1 }),
        ).toBeVisible();

        const { scrollWidth, clientWidth } = await page.evaluate(() => ({
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        }));
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth);

        // Move the mouse away before scanning so no control is caught in a
        // spurious :hover state (matches server-operations.spec.ts's own
        // mobile axe scan convention).
        await page.mouse.move(0, 0);

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
            .analyze();
        expect(results.violations).toEqual([]);
    });
});
