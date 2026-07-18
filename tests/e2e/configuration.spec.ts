import fs from 'node:fs';
import path from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Task 9 — configuration inventory and editor experience.
 *
 * Naming note: the plan's own file list names this
 * `tests/Browser/ConfigEditorTest.php`, but this repository's actual e2e
 * convention (established by Tasks 3–4 — see playwright.config.ts and
 * tests/e2e/design-system.spec.ts / onboarding.spec.ts) is a Playwright
 * TypeScript spec under tests/e2e run via `npm run e2e`, not a PHP
 * "Browser" test class (no Laravel Dusk dependency exists in this
 * project). This file follows the established, actually-working
 * convention rather than the plan's literal (and, for this stack,
 * unreachable) path — the same kind of scheduling/naming reconciliation
 * documented for earlier tasks in docs/architecture/decisions.md.
 *
 * A real secret value is injected into the e2e-only Minecraft root copy
 * (see playwright.config.ts's E2E_MINECRAFT_ROOT) before any test runs,
 * so the redaction assertions below are exercising the real mechanism,
 * not a vacuous fixture with nothing to redact.
 *
 * This file creates its OWN admin account (`ADMIN_EMAIL`/`ADMIN_PASSWORD`
 * below), so it cannot assume the database is still in the "no admin"
 * state `playwright.config.ts`'s `webServer` left it in at boot — another
 * spec file (e.g. onboarding.spec.ts) may have already run and created a
 * *different* admin with different credentials, which would make this
 * file's own `ensureLoggedInAdmin()` fall into its "log in" branch with
 * the wrong password and hang. `beforeAll` below resets the database via
 * the test-only `/__e2e__/reset` endpoint (see routes/testing.php) before
 * anything else runs, so this file always starts from zero users and
 * `ensureLoggedInAdmin()` always takes its "create the admin" branch on
 * this file's first test, regardless of what ran before it.
 */
const ADMIN_EMAIL = 'admin@craftkeeper.test';
const ADMIN_PASSWORD = 'a-very-long-unique-config-e2e-passphrase';
const SECRET_VALUE = 'e2e-secret-rcon-password-should-never-leak';

// Matches E2E_MINECRAFT_ROOT in playwright.config.ts — `process.cwd()`,
// not `__dirname`, since this package is `"type": "module"` (no CJS
// `__dirname` global) and Playwright always runs with the repo root as
// the working directory.
const MINECRAFT_ROOT = path.resolve(
    process.cwd(),
    'storage/craftkeeper/e2e-minecraft',
);

test.beforeAll(async ({ request }) => {
    const reset = await request.post('/__e2e__/reset');
    if (!reset.ok()) {
        throw new Error(
            `Failed to reset the e2e database before configuration.spec.ts (status ${reset.status()}). ` +
                'Is E2E_TESTING=true set on the webServer process (see playwright.config.ts)?',
        );
    }

    const propertiesPath = path.join(MINECRAFT_ROOT, 'server.properties');
    const contents = fs.readFileSync(propertiesPath, 'utf8');

    if (!contents.includes('rcon.password=')) {
        fs.appendFileSync(propertiesPath, `rcon.password=${SECRET_VALUE}\n`);
    }
});

async function ensureLoggedInAdmin(page: Page): Promise<void> {
    const welcomeResponse = await page.goto('/onboarding');

    if (welcomeResponse?.status() === 404) {
        // Already onboarded by an earlier test in this file — just log in.
        await page.goto('/login');
        await page.locator('#email').fill(ADMIN_EMAIL);
        await page.locator('#password').fill(ADMIN_PASSWORD);
        await page.getByTestId('login-button').click();
        await page.waitForURL('**/dashboard');

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
    await page.getByRole('link', { name: 'Go to dashboard' }).click();
    await page.waitForURL('**/dashboard');
}

test.describe.serial('configuration', () => {
    test.beforeEach(async ({ page }) => {
        await ensureLoggedInAdmin(page);
    });

    test('never leaks the secret rcon password across guided, structured, and source props', async ({
        page,
    }) => {
        await page.goto('/configurations/server.properties');
        await expect(
            page.getByRole('heading', { name: 'server.properties' }),
        ).toBeVisible();

        // Guided mode (default tab): the secret field shows the sentinel.
        await expect(page.locator('body')).not.toContainText(SECRET_VALUE);
        const rconInput = page.getByTestId('guided-field-rcon.password');
        await expect(rconInput).toHaveValue('••••••');

        // Structured mode.
        await page.getByTestId('config-tab-structured').click();
        await expect(page.locator('body')).not.toContainText(SECRET_VALUE);
        await expect(
            page.getByTestId('structured-leaf-rcon.password'),
        ).toHaveValue('••••••');

        // Source mode.
        await page.getByTestId('config-tab-source').click();
        await expect(page.locator('body')).not.toContainText(SECRET_VALUE);
        await expect(page.getByTestId('source-editor')).toContainText(
            '••••••',
        );

        // Belt and suspenders: the raw HTML document (the Inertia JSON
        // payload is inlined into it on first load) never contains it
        // either.
        expect(await page.content()).not.toContain(SECRET_VALUE);
    });

    test('the inventory list never leaks the secret value either', async ({
        page,
    }) => {
        await page.goto('/configurations');
        await expect(
            page.getByRole('heading', { name: 'Configurations' }),
        ).toBeVisible();
        expect(await page.content()).not.toContain(SECRET_VALUE);
    });

    test('guided and source mode propose reviewable changes for the same file, and keyboard-only save/review works', async ({
        page,
    }) => {
        await page.goto('/configurations/server.properties');

        // --- Guided mode: toggle allow-flight with the keyboard only. ---
        const guidedCheckbox = page.getByTestId('guided-field-allow-flight');
        await guidedCheckbox.focus();
        await page.keyboard.press('Space');

        const reviewButton = page.getByTestId('config-propose');
        await reviewButton.focus();
        await page.keyboard.press('Enter');

        const review = page.getByLabel('Pending change review');
        await expect(review).toBeVisible();
        await expect(review).toContainText('allow-flight');

        const approveButton = page.getByTestId('diff-review-approve');
        await approveButton.focus();
        await page.keyboard.press('Enter');

        await expect(page.getByText('Applied')).toBeVisible({
            timeout: 10_000,
        });

        // --- Source mode: the SAME domain field, edited as text. ---
        await page.getByTestId('config-tab-source').click();
        const editor = page.getByTestId('source-editor');
        const currentSource = await editor.inputValue();
        await editor.fill(currentSource.replace('online-mode=true', 'online-mode=false'));
        await page.getByTestId('config-propose').click();

        const sourceReview = page.getByLabel('Pending change review');
        await expect(sourceReview).toBeVisible();
        await expect(sourceReview).toContainText('online-mode');

        await page.getByTestId('diff-review-reject').click();
        await expect(page.getByText('Change discarded')).toBeVisible({
            timeout: 10_000,
        });
    });

    test('a stale edit shows the conflict view with base/disk/proposed values and a resolution action', async ({
        page,
    }) => {
        await page.goto('/configurations/server.properties');

        // Simulate an external actor (the Minecraft server itself, an
        // admin editing the file directly, ...) changing the file on disk
        // AFTER this page already loaded its base_sha256 — the same
        // scenario tests/Feature/Config/ConfigConflictTest.php exercises
        // at the service layer.
        const propertiesPath = path.join(MINECRAFT_ROOT, 'server.properties');
        const beforeExternalEdit = fs.readFileSync(propertiesPath, 'utf8');
        fs.writeFileSync(
            propertiesPath,
            beforeExternalEdit.replace('motd=', 'motd=changed-externally-'),
        );

        try {
            const motdInput = page.getByTestId('guided-field-motd');
            await motdInput.fill('operator typed motd');
            await page.getByTestId('config-propose').click();

            await expect(
                page.getByRole('heading', {
                    name: /changed outside CraftKeeper/,
                }),
            ).toBeVisible();
            await expect(page.getByText('Your base')).toBeVisible();
            await expect(page.getByText('Currently on disk')).toBeVisible();
            await expect(page.getByText('Your proposed changes')).toBeVisible();
            await expect(
                page.getByTestId('conflict-create-proposal'),
            ).toBeVisible();
            await expect(
                page.getByTestId('conflict-reload-disk'),
            ).toBeVisible();
        } finally {
            // Leave the file exactly how the next test expects it.
            fs.writeFileSync(propertiesPath, beforeExternalEdit);
        }
    });

    test('the mobile bottom-sheet review keeps approval controls reachable, with no hidden controls, and is axe-clean', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/configurations/server.properties');

        await page.getByTestId('guided-field-allow-flight').click();
        await page.getByTestId('config-propose').click();

        const review = page.getByLabel('Pending change review');
        await expect(review).toBeVisible();

        const approve = page.getByTestId('diff-review-approve');
        const reject = page.getByTestId('diff-review-reject');

        await expect(approve).toBeVisible();
        await expect(reject).toBeVisible();
        await expect(approve).not.toHaveAttribute('hidden');

        // Actually reachable, not just present in the DOM: scroll it into
        // view and confirm it is within the viewport bounding box.
        await approve.scrollIntoViewIfNeeded();
        const box = await approve.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.y).toBeGreaterThanOrEqual(0);
        expect(box!.y).toBeLessThanOrEqual(844);

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
            .analyze();

        expect(results.violations).toEqual([]);

        // Leave the file how the next run expects it.
        await reject.click();
    });
});
