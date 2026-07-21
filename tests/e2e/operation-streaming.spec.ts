import { expect, test } from '@playwright/test';
import type { BrowserContext, Page } from '@playwright/test';

/**
 * Live operation-progress streaming, end to end: a status change made
 * OUTSIDE a page must reach that page over the websocket and re-render it,
 * with no navigation and no polling.
 *
 * This is the one spec in the suite that depends on a real Laravel Reverb
 * server. playwright.config.ts starts one alongside `artisan serve` and
 * points both halves of the app at it (BROADCAST_CONNECTION=reverb for the
 * publisher, VITE_REVERB_* for the bundle) — so nothing here is simulated:
 * a real App\Events\OperationUpdated is published by the real broadcaster,
 * travels through the real Pusher-protocol socket, and is handled by the
 * real resources/js/features/operations/OperationProgress.tsx.
 *
 * WHY THE OUT-OF-BAND TRIGGER MATTERS. The transition is driven from a
 * SECOND page in the same browser context, not from the watching page's
 * own controls. Approving from the watching page would prove nothing: that
 * click is a full Inertia visit which re-renders the panel from the
 * server's response, so the UI would update identically with the websocket
 * unplugged. The second page makes the only path from the state change to
 * the first page's DOM the broadcast itself. (It is also just what an
 * operator with two tabs open actually experiences.)
 *
 * WHAT THIS DOES NOT COVER. `artisan serve` cannot proxy a websocket, so
 * the browser here connects straight to Reverb using a key Vite compiled
 * into the bundle — resources/js/lib/echo.ts's build-time branch, which is
 * how local development runs. The published image has no build-time key at
 * all and takes the other branch: the key arrives in a <meta> tag and the
 * socket opens against the page's own origin, proxied by Nginx. That path
 * is covered by tests/Feature/RealtimeClientConfigTest.php,
 * resources/js/lib/echo.test.ts, and docker-compose.integration.yml.
 *
 * Its own admin account and its own `/__e2e__/reset`, like every other
 * spec file here — see tests/e2e/server-operations.spec.ts's docblock for
 * why each file owns its baseline, and why one signed-in page is shared
 * across a file's tests rather than logging in per test.
 */
const ADMIN_EMAIL = 'admin@craftkeeper.test';
const ADMIN_PASSWORD = 'a-very-long-unique-operation-streaming-e2e-passphrase';

/**
 * An Elevated command by App\Console\CommandPolicy's rules (its Safe
 * allow-list holds `time query daytime`, not this), so it proposes an
 * Operation and waits for approval — which is the lifecycle this spec
 * watches. Deliberately inert: RCON is unavailable throughout the e2e
 * suite so it is never actually sent, and it would be a read-only query
 * even if it were.
 */
const ELEVATED_COMMAND = 'time query gametime';

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

test.describe.serial('operation progress streaming', () => {
    let context: BrowserContext;
    let page: Page;

    test.beforeAll(async ({ browser, request }) => {
        const reset = await request.post('/__e2e__/reset');

        if (!reset.ok()) {
            throw new Error(
                `Failed to reset the e2e database before operation-streaming.spec.ts (status ${reset.status()}). ` +
                    'Is E2E_TESTING=true set on the webServer process (see playwright.config.ts)?',
            );
        }

        context = await browser.newContext();
        page = await context.newPage();
        await ensureLoggedInAdmin(page);
    });

    test.afterAll(async () => {
        await context.close();
    });

    test('streams an operation lifecycle into an already-open page', async () => {
        // Every text frame this page receives, so the assertions below can
        // check the WIRE contract (channel name, event name, payload
        // fields) and not just its rendered effect — a DOM-only assertion
        // would still pass if the payload were renamed and the component
        // renamed to match, quietly breaking every other consumer.
        const framesReceived: string[] = [];
        const socketUrls: string[] = [];

        page.on('websocket', (socket) => {
            socketUrls.push(socket.url());
            socket.on('framereceived', (frame) => {
                if (typeof frame.payload === 'string') {
                    framesReceived.push(frame.payload);
                }
            });
        });

        await page.goto('/server/console');

        // Precondition, asserted rather than assumed: the socket really is
        // up. Without this a broken Reverb would surface further down as a
        // bare "expected Failed, got Awaiting approval" timeout, which
        // reads like a product bug rather than a harness one. The banner
        // is rendered for every non-connected state (see
        // resources/js/pages/server/Console.tsx), so its ABSENCE is
        // precisely "connected".
        await expect(
            page.getByTestId('console-reconnect-banner'),
            'Echo never reached "connected" — is `artisan reverb:start` running? See playwright.config.ts webServer.command.',
        ).toBeHidden({ timeout: 20_000 });

        // Propose the operation from the watching page itself. Only the
        // TRANSITION has to arrive out of band; how the operation came to
        // exist is irrelevant, and doing it here is what puts a subscribed
        // OperationProgress on screen.
        await page.getByTestId('command-input').fill(ELEVATED_COMMAND);
        await page.getByRole('button', { name: 'Compose command' }).click();
        await page.getByTestId('request-approval').click();

        const progress = page.getByTestId('operation-progress');
        await expect(progress).toContainText('Awaiting approval');

        const operationId = new URL(page.url()).searchParams.get('operation');
        expect(
            operationId,
            'Proposing an elevated command should redirect to ?operation=<id>',
        ).toBeTruthy();

        // A witness for "this is still the same document". If anything
        // navigated or reloaded the page, `window` is rebuilt and this is
        // gone — which would mean the UI change below proved nothing.
        await page.evaluate(() => {
            (window as unknown as Record<string, unknown>).__ckStreamingWitness =
                'same-document';
        });

        // The out-of-band transition. ConsoleController::approve() both
        // approves AND executes, so this publishes the whole tail of the
        // lifecycle (approved -> running -> terminal) in one request.
        const approver = await context.newPage();
        await approver.goto(`/server/console?operation=${operationId}`);
        await approver.getByTestId('approve-command').click();
        await approver.waitForURL(`**/server/console?operation=${operationId}`);
        await approver.close();

        // The watching page was never touched — it must have moved on its
        // own. Asserted as "left the pending state" AND "reached a
        // terminal one": RCON is unavailable across the e2e suite so the
        // send fails, but this spec is about the update ARRIVING, not
        // about which terminal state it carries.
        await expect(progress).not.toContainText('Awaiting approval');
        await expect(progress).toContainText(/Succeeded|Failed/);

        const witness = await page.evaluate(
            () =>
                (window as unknown as Record<string, unknown>)
                    .__ckStreamingWitness,
        );
        expect(
            witness,
            'The watching page reloaded, so this proves nothing about streaming',
        ).toBe('same-document');
        expect(new URL(page.url()).searchParams.get('operation')).toBe(
            operationId,
        );

        // The socket opened where the bundle was told to open it, on the
        // Pusher protocol's `/app/{key}` path.
        expect(socketUrls.some((url) => url.includes('/app/'))).toBe(true);

        // And the update genuinely came down THAT socket, on this
        // operation's private channel, carrying the field names
        // App\Events\OperationUpdated::broadcastWith() promises and
        // OperationProgress.tsx's OperationUpdatedPayload consumes.
        const updates = framesReceived
            .map((payload) => {
                try {
                    return JSON.parse(payload) as {
                        event?: string;
                        channel?: string;
                        data?: string;
                    };
                } catch {
                    return null;
                }
            })
            .filter(
                (frame): frame is { event: string; channel: string; data: string } =>
                    frame?.event?.replace(/^\./, '') === 'operation.updated',
            );

        expect(
            updates.length,
            `No operation.updated frame arrived. Frames seen: ${JSON.stringify(framesReceived)}`,
        ).toBeGreaterThan(0);

        for (const update of updates) {
            expect(update.channel).toBe(`private-operations.${operationId}`);
        }

        const payload = JSON.parse(updates[updates.length - 1].data) as Record<
            string,
            unknown
        >;
        expect(payload.id).toBe(operationId);
        expect(payload.status).toMatch(/succeeded|failed/);
        expect(payload).toHaveProperty('outcome');
        expect(payload).toHaveProperty('error_code');

        // The wire payload stays the sanitized projection: never the raw
        // `target` (for an rcon.command that is the literal command text)
        // and never the stored input. Guards the allow-list in
        // OperationUpdated's docblock against a future field being added
        // to the event without anyone re-reading why it is an allow-list.
        expect(payload).not.toHaveProperty('target');
        expect(payload).not.toHaveProperty('redacted_input');
        expect(JSON.stringify(payload)).not.toContain(ELEVATED_COMMAND);

        page.removeAllListeners('websocket');
    });
});
