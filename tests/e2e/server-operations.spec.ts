import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Task 12 — Overview, Server, Players, Console, Logs, and Activity UI.
 *
 * Naming note (same reconciliation as Tasks 3/4/9 — see
 * docs/architecture/decisions.md): the brief's own Step 1 test is written
 * in Pest v4 browser-testing syntax (`visit()`, `->press()`,
 * `->assertNoJavascriptErrors()`). `pestphp/pest-plugin-browser` was
 * installed and tried for real in this sandbox; its Playwright-bridge
 * server (`node_modules/.bin/playwright run-server`, launched via Amp's
 * async runtime) hung indefinitely on every attempt, including a bare
 * `/design-system` smoke test with no CraftKeeper-specific code involved —
 * an environment limitation of that specific dependency in this sandbox,
 * not a product bug. The dependency was removed again (composer.json/
 * lock are clean of it). This file follows the repo's actual, already-
 * proven-working e2e convention instead (Playwright TypeScript under
 * tests/e2e, `npm run e2e`), reproducing the brief's exact interaction
 * (`type 'stop' into [data-test=command-input]`, press "Compose command",
 * see "Stops the Minecraft server" and "Approval required", no JS errors)
 * verbatim in that convention.
 *
 * No `schedule:work`/`SampleServerState` scheduler runs anywhere in this
 * sandbox, so throughout this entire file RCON is deterministically
 * "unavailable" (no App\Models\ServerSample row is ever created) — a
 * genuinely observed state, not a simulated one.
 *
 * A live Reverb server, however, now DOES run alongside `artisan serve`
 * (playwright.config.ts starts one so tests/e2e/operation-streaming.spec.ts
 * can assert on real streaming). Echo therefore reaches "connected" here,
 * where it once could not — which is why the websocket-loss test at the
 * bottom of this file severs its own connection rather than relying on
 * there being nothing to connect to.
 *
 * ONE shared, authenticated page for the whole file (created once in
 * `beforeAll`, reused by every test below instead of the default
 * per-test `page` fixture): logging in fresh for each of this file's many
 * tests would race Fortify's own 5-attempts-per-minute login throttle
 * (Task 4) — a real, previously-observed flake in this exact shape
 * (`ensureLoggedInAdmin`'s login branch hanging on `waitForURL` after the
 * 5th or 6th fresh login within a minute). Reusing one already-signed-in
 * session across tests in the same file is also simply how an operator
 * actually uses this app in one sitting.
 */
const ADMIN_EMAIL = 'admin@craftkeeper.test';
const ADMIN_PASSWORD = 'a-very-long-unique-server-ops-e2e-passphrase';

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

test.describe.serial('server operations', () => {
    let page: Page;

    test.beforeAll(async ({ browser, request }) => {
        const reset = await request.post('/__e2e__/reset');
        if (!reset.ok()) {
            throw new Error(
                `Failed to reset the e2e database before server-operations.spec.ts (status ${reset.status()}). ` +
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

    /*
    |----------------------------------------------------------------------
    | Step 1, verbatim: consequence review before an elevated command
    |----------------------------------------------------------------------
    */

    test('requires consequence review before sending an elevated command', async () => {
        const jsErrors: Error[] = [];
        page.on('pageerror', (error) => jsErrors.push(error));

        await page.goto('/server/console');
        await page.getByTestId('command-input').fill('stop');
        await page.getByRole('button', { name: 'Compose command' }).click();

        await expect(page.getByText('Stops the Minecraft server.')).toBeVisible();
        await expect(page.getByText('Approval required')).toBeVisible();

        expect(jsErrors).toEqual([]);
        page.removeAllListeners('pageerror');
    });

    test('a safe command composes without requiring approval and offers "Run now"', async () => {
        await page.goto('/server/console');
        await page.getByTestId('command-input').fill('list');
        await page.getByRole('button', { name: 'Compose command' }).click();

        await expect(
            page.getByText('Lists players currently online. Read-only.'),
        ).toBeVisible();
        await expect(page.getByText('Approval required')).not.toBeVisible();
        await expect(page.getByTestId('run-now')).toBeVisible();
    });

    test('an unrecognized command defaults to Elevated, per CommandPolicy default-deny', async () => {
        await page.goto('/server/console');
        await page.getByTestId('command-input').fill('totally-unknown-command');
        await page.getByRole('button', { name: 'Compose command' }).click();

        await expect(page.getByText('Approval required')).toBeVisible();
        await expect(
            page.getByText(
                'This command is not on the predefined safe list and may change server or player state.',
            ),
        ).toBeVisible();
    });

    /*
    |----------------------------------------------------------------------
    | The full gate: propose shows a review panel; only a fresh, separate
    | approval click can lead to a real (here, failing — no live server)
    | RCON attempt.
    |----------------------------------------------------------------------
    */

    test('proposing an elevated command opens a review panel that requires a separate approval click before anything is sent', async () => {
        await page.goto('/server/console');
        await page.getByTestId('command-input').fill('op Steve');
        await page.getByRole('button', { name: 'Compose command' }).click();
        await page.getByTestId('request-approval').click();

        const panel = page.getByTestId('console-approval-panel');
        await expect(panel).toBeVisible();
        await expect(panel).toContainText('Approval required');

        const approve = page.getByTestId('approve-command');
        const reject = page.getByTestId('reject-command');
        await expect(approve).toBeVisible();
        await expect(reject).toBeVisible();

        // Proposed, not yet acted on — no RCON attempt has happened yet.
        await expect(page.getByText('Awaiting approval')).toBeVisible();

        // A fresh approval click is the ONLY thing that leads to a real
        // attempt — there is no live Minecraft/RCON server anywhere in
        // this sandbox, so the honest outcome is a Failed operation, not a
        // silent success. What matters here is WHEN the attempt happens
        // (strictly after this click), not that it happens to fail.
        await approve.click();
        await expect(page.getByText(/Succeeded|Failed/)).toBeVisible({
            timeout: 10_000,
        });
    });

    test('rejecting a proposed elevated command discards it without approval controls disappearing unexpectedly', async () => {
        await page.goto('/server/console');
        await page.getByTestId('command-input').fill('deop Steve');
        await page.getByRole('button', { name: 'Compose command' }).click();
        await page.getByTestId('request-approval').click();

        await expect(page.getByTestId('reject-command')).toBeVisible();
        await page.getByTestId('reject-command').click();

        await expect(page.getByText('Command discarded')).toBeVisible({
            timeout: 10_000,
        });
    });

    /*
    |----------------------------------------------------------------------
    | Degraded RCON: only RCON-dependent cards degrade; Logs stays usable;
    | never a fabricated zero.
    |----------------------------------------------------------------------
    */

    test('shows RCON as Unavailable with a reason on Overview and Server — never a fabricated zero', async () => {
        await page.goto('/overview');
        await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible();
        await expect(page.getByText('Unavailable').first()).toBeVisible();
        await expect(page.getByTestId('online-player-count')).toHaveCount(0);

        await page.goto('/server');
        await expect(page.getByRole('heading', { name: 'Server' })).toBeVisible();
        await expect(page.getByText('Unavailable').first()).toBeVisible();

        await page.goto('/server/players');
        await expect(page.getByRole('heading', { name: 'Players' })).toBeVisible();
        await expect(page.getByText(/Online status is unknown/)).toBeVisible();
    });

    test('the file-based Logs page stays usable while RCON is unavailable', async () => {
        await page.goto('/server/logs');
        await expect(page.getByRole('heading', { name: 'Logs' })).toBeVisible();
        // No "Logs unavailable" PageState card — the log FILE exists in
        // the e2e fixture even though RCON does not.
        await expect(page.getByText('Logs unavailable')).not.toBeVisible();
    });

    /*
    |----------------------------------------------------------------------
    | Responsive layouts at the desktop breakpoint — mocked pages match
    | their desktop hierarchy (a real heading + real primary content).
    |----------------------------------------------------------------------
    */

    const desktopPages: Array<{ path: string; heading: string }> = [
        { path: '/overview', heading: 'Overview' },
        { path: '/server', heading: 'Server' },
        { path: '/server/players', heading: 'Players' },
        { path: '/server/console', heading: 'Console' },
        { path: '/server/logs', heading: 'Logs' },
        { path: '/activity', heading: 'Activity' },
    ];

    for (const { path, heading } of desktopPages) {
        test(`${path} renders its desktop hierarchy with no horizontal scroll and is axe-clean`, async () => {
            await page.setViewportSize({ width: 1440, height: 1000 });
            await page.goto(path);
            await expect(
                page.getByRole('heading', { name: heading, level: 1 }),
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

    /*
    |----------------------------------------------------------------------
    | Mobile: the console + approval bottom sheet stay usable at 390x844,
    | with no hidden approval controls.
    |----------------------------------------------------------------------
    */

    test('the mobile console and approval bottom sheet keep every control reachable, with none hidden, and is axe-clean', async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/server/console');
        await expect(
            page.getByRole('heading', { name: 'Console', level: 1 }),
        ).toBeVisible();

        const input = page.getByTestId('command-input');
        await expect(input).toBeVisible();
        await input.fill('stop');
        await page.getByRole('button', { name: 'Compose command' }).click();
        await expect(page.getByText('Approval required')).toBeVisible();

        await page.getByTestId('request-approval').click();

        const approve = page.getByTestId('approve-command');
        const reject = page.getByTestId('reject-command');
        await expect(approve).toBeVisible();
        await expect(reject).toBeVisible();
        await expect(approve).not.toHaveAttribute('hidden');
        await expect(reject).not.toHaveAttribute('hidden');

        await approve.scrollIntoViewIfNeeded();
        const box = await approve.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.y).toBeGreaterThanOrEqual(0);
        expect(box!.y).toBeLessThanOrEqual(844);

        const { scrollWidth, clientWidth } = await page.evaluate(() => ({
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        }));
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth);

        // Move the pointer away before scanning: `scrollIntoViewIfNeeded()`
        // above leaves the real cursor sitting wherever it last was, which
        // can land it on top of "Approve & send" purely by viewport
        // coincidence and trip its (real, but hover-only) `hover:bg-primary/90`
        // state — a test-interaction artifact, not the resting state a
        // real operator sees before actually touching the button.
        await page.mouse.move(0, 0);

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
            .analyze();
        expect(results.violations).toEqual([]);

        // Leave the operation in a known terminal state for isolation.
        await reject.click();

        // Restore the default viewport for whatever test runs next.
        await page.setViewportSize({ width: 1280, height: 800 });
    });

    /*
    |----------------------------------------------------------------------
    | The application shell reports real state, not a placeholder.
    |----------------------------------------------------------------------
    */

    test('the shell reports real server state, never the design-system mock', async () => {
        await page.goto('/overview');

        const shell = page.locator('[data-ck-app-shell], body');

        // Until 1.1.1 AppShell.tsx carried DEFAULT_SERVER as a component
        // default and nothing ever overrode it, so every page of every
        // install rendered this. It could only ever be caught here: the
        // strings were compiled into the JS bundle, so no server-side
        // assertion on the HTML could see them.
        for (const mock of ['mc.example.net', 'Paper 1.21.4', 'Survival', '3 / 40']) {
            await expect(shell).not.toContainText(mock);
        }

        // RCON is deterministically unavailable across this suite (no
        // sampler runs), so the honest answer is that the count is unknown
        // — NOT a fabricated 0, and not a stale number.
        await expect(page.getByTestId('shell-player-count')).toContainText(
            'Players unknown',
        );
    });

    /*
    |----------------------------------------------------------------------
    | Websocket loss: a reconnect state is shown, and it never clears
    | typed-but-unsent input.
    |----------------------------------------------------------------------
    */

    test('shows a reconnect state when live updates are unavailable, without losing composed input', async () => {
        // Its OWN page, in the shared (already signed-in) context: the
        // websocket route below would otherwise stay installed on the
        // file's shared page and quietly disconnect any test added after
        // this one. No extra login, so Fortify's throttle is untouched.
        const severed = await page.context().newPage();

        // A real Reverb server is running (see this file's docblock), so
        // the unavailable state has to be produced rather than assumed:
        // intercept the Pusher-protocol socket and close it instead of
        // connecting it through. That is a genuine failed connection as
        // far as Echo is concerned — the same thing it sees when Reverb
        // is down or a proxy drops the upgrade — not a stubbed status.
        await severed.routeWebSocket('**/app/**', (ws) => ws.close());

        await severed.goto('/server/console');

        await expect(
            severed.getByTestId('console-reconnect-banner'),
        ).toBeVisible({ timeout: 10_000 });

        const input = severed.getByTestId('command-input');
        await input.fill('this command is still being typed');

        // Give the reconnect attempt a moment to have possibly fired a
        // re-render, then confirm the operator's text survived it.
        await severed.waitForTimeout(500);
        await expect(input).toHaveValue('this command is still being typed');

        await severed.close();
    });

    /*
    |----------------------------------------------------------------------
    | The account menu can actually sign out.
    |----------------------------------------------------------------------
    */

    test('the account menu signs the operator out', async ({ browser }) => {
        // Its OWN context, deliberately: signing out clears the session
        // cookie, and this file's other tests share one signed-in context.
        // Kept last in the file so the extra login cannot contribute to
        // Fortify's per-minute throttle during the tests above.
        const context = await browser.newContext();
        const fresh = await context.newPage();
        await ensureLoggedInAdmin(fresh);

        await fresh.goto('/overview');
        await fresh.getByTestId('shell-account-menu').click();

        // Until 1.1.1 this was a DISABLED item reading "Sign out (available
        // once sign-in ships)" — so the shell's own account menu offered no
        // way out at all, long after sign-in shipped.
        const signOut = fresh.getByTestId('shell-logout-button');
        await expect(signOut).toBeVisible();
        await signOut.click();

        await fresh.waitForURL('**/login');
        await expect(fresh.locator('#email')).toBeVisible();

        await context.close();
    });
});
