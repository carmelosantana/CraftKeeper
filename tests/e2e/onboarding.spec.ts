import { expect, test } from '@playwright/test';
import { generateTotp } from './support/totp';

/**
 * Task 4 — single-admin onboarding, login, TOTP, and secrets.
 *
 * These three tests share one real server-side install (the sqlite
 * database `playwright.config.ts`'s `webServer` resets via `migrate:fresh`
 * before each fresh run) and depend on running in this order: onboarding
 * creates the one-and-only admin account, login proves it works, and
 * two-factor builds on both. `test.describe.serial` guarantees that order
 * and that they never run concurrently with each other.
 */
const ADMIN_EMAIL = 'admin@craftkeeper.test';
const ADMIN_PASSWORD = 'a-very-long-unique-onboarding-passphrase';
const RCON_PASSWORD = 'a-super-secret-e2e-rcon-password';

test.describe.serial('onboarding, login, and two-factor', () => {
    let recoveryCode = '';

    test('onboarding: first-run setup is reachable without login and disappears once complete', async ({
        page,
    }) => {
        const welcomeResponse = await page.goto('/onboarding');
        expect(welcomeResponse?.ok()).toBeTruthy();
        await expect(
            page.getByRole('heading', { name: 'Welcome to CraftKeeper' }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Get started' }).click();
        await expect(
            page.getByRole('heading', {
                name: 'Create your administrator account',
            }),
        ).toBeVisible();

        await page.locator('#name').fill('Admin');
        await page.locator('#email').fill(ADMIN_EMAIL);
        await page.locator('#password').fill(ADMIN_PASSWORD);
        await page.locator('#password_confirmation').fill(ADMIN_PASSWORD);
        await page.getByTestId('onboarding-admin-button').click();

        await page.waitForURL('**/onboarding/server');

        // Skip the Minecraft directory check — it's optional/mocked.
        await page.getByRole('link', { name: 'Skip for now' }).click();
        await page.waitForURL('**/onboarding/rcon');

        // Save a real RCON password, then prove it's never echoed back —
        // neither in the response that saved it, nor when the step is
        // revisited afterward.
        await page.locator('#rcon_password').fill(RCON_PASSWORD);
        await page.getByRole('button', { name: 'Save & continue' }).click();
        await page.waitForURL('**/onboarding/ai');
        expect(await page.content()).not.toContain(RCON_PASSWORD);

        await page.getByRole('link', { name: 'Skip for now' }).click();
        await page.waitForURL('**/onboarding/analytics');

        await page.getByRole('link', { name: 'Skip for now' }).click();
        await page.waitForURL('**/onboarding/complete');
        await expect(
            page.getByRole('heading', { name: "You're all set" }),
        ).toBeVisible();

        // Revisiting the step must not echo the secret's *value* back —
        // the field name "rcon_password" legitimately appears in the form
        // markup and the `rconPasswordConfigured` boolean prop; neither of
        // those is the secret.
        await page.goto('/onboarding/rcon');
        expect(await page.content()).not.toContain(RCON_PASSWORD);

        await page.goto('/onboarding/complete');
        await page.getByRole('link', { name: 'Go to dashboard' }).click();
        await page.waitForURL('**/dashboard');

        // Registration is gone for good — a 404, not a hidden UI element
        // or a redirect a determined user could route around.
        const secondVisit = await page.goto('/onboarding');
        expect(secondVisit?.status()).toBe(404);
    });

    test('login: the created admin can log in with email and password', async ({
        page,
    }) => {
        await page.goto('/login');
        await page.locator('#email').fill(ADMIN_EMAIL);
        await page.locator('#password').fill(ADMIN_PASSWORD);
        await page.getByTestId('login-button').click();

        await page.waitForURL('**/dashboard');
    });

    test('two-factor: enabling TOTP and logging in with a recovery code works', async ({
        page,
    }) => {
        await page.goto('/login');
        await page.locator('#email').fill(ADMIN_EMAIL);
        await page.locator('#password').fill(ADMIN_PASSWORD);
        await page.getByTestId('login-button').click();
        await page.waitForURL('**/dashboard');

        await page.goto('/settings/security');

        // A fresh login isn't a "confirmed" password session yet —
        // Fortify requires re-confirming the password before managing 2FA.
        if (page.url().includes('/user/confirm-password')) {
            await page.locator('#password').fill(ADMIN_PASSWORD);
            await page.getByTestId('confirm-password-button').click();
            await page.waitForURL('**/settings/security');
        }

        await page.getByRole('button', { name: 'Enable 2FA' }).click();

        // The manual setup key is the same secret the QR code encodes —
        // read it and compute a real, valid TOTP code from it (see
        // support/totp.ts) rather than faking the confirmation.
        const manualKeyInput = page.locator('input[readonly]');
        await expect(manualKeyInput).not.toHaveValue('', { timeout: 10_000 });
        const manualKey = await manualKeyInput.inputValue();

        await page.getByRole('button', { name: 'Continue' }).click();

        const code = generateTotp(manualKey);
        await page.locator('input[name="code"]').fill(code);
        await page.getByRole('button', { name: 'Confirm' }).click();

        await expect(
            page.getByRole('button', { name: 'Disable 2FA' }),
        ).toBeVisible({ timeout: 10_000 });

        await page.getByRole('button', { name: 'View recovery codes' }).click();
        const firstCode = page.locator('[role="listitem"]').first();
        await expect(firstCode).toBeVisible({ timeout: 10_000 });
        recoveryCode = (await firstCode.textContent())?.trim() ?? '';
        expect(recoveryCode.length).toBeGreaterThan(0);

        // Simulate a fresh session and log back in — 2FA must now kick in.
        await page.context().clearCookies();
        await page.goto('/login');
        await page.locator('#email').fill(ADMIN_EMAIL);
        await page.locator('#password').fill(ADMIN_PASSWORD);
        await page.getByTestId('login-button').click();

        await page.waitForURL('**/two-factor-challenge');

        await page
            .getByRole('button', { name: 'login using a recovery code' })
            .click();
        await page.locator('input[name="recovery_code"]').fill(recoveryCode);
        await page.getByRole('button', { name: 'Continue' }).click();

        await page.waitForURL('**/dashboard');
    });
});
