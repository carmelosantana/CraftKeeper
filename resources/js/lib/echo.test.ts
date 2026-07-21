import { describe, expect, it, vi } from 'vitest';

/**
 * Regression cover for the blank-page bug.
 *
 * `VITE_*` values are inlined by Vite at build time and the container image
 * builds with no `.env`, so in the published image `VITE_REVERB_APP_KEY` is
 * `undefined`. Configuring the `reverb` broadcaster with that key made
 * Pusher's constructor throw during render, and Assistant, the Console, and
 * every page rendering OperationProgress served a blank page — at HTTP 200,
 * which is why nothing caught it.
 */
describe('bootEcho', () => {
    async function bootWith(
        key: string | undefined,
        runtimeKey: string | null = null,
    ) {
        vi.resetModules();
        document.head.innerHTML = '';

        if (runtimeKey !== null) {
            const meta = document.createElement('meta');
            meta.setAttribute('name', 'craftkeeper-reverb-key');
            meta.setAttribute('content', runtimeKey);
            document.head.appendChild(meta);
        }

        const configureEcho = vi.fn();

        vi.doMock('@laravel/echo-react', () => ({
            configureEcho,
            echoIsConfigured: () => false,
        }));

        vi.stubEnv('VITE_REVERB_APP_KEY', key as string);

        const mod = await import('./echo');
        mod.bootEcho();

        return { configureEcho, realtimeEnabled: mod.realtimeEnabled };
    }

    it('falls back to the inert null broadcaster when no Reverb key is built in', async () => {
        const { configureEcho, realtimeEnabled } = await bootWith(undefined);

        expect(configureEcho).toHaveBeenCalledWith({ broadcaster: 'null' });
        expect(realtimeEnabled).toBe(false);
    });

    it('treats a blank key as no key', async () => {
        const { configureEcho, realtimeEnabled } = await bootWith('   ');

        expect(configureEcho).toHaveBeenCalledWith({ broadcaster: 'null' });
        expect(realtimeEnabled).toBe(false);
    });

    it('uses Reverb when a build-time key really is present', async () => {
        const { configureEcho, realtimeEnabled } = await bootWith('local-key');

        expect(configureEcho).toHaveBeenCalledWith({ broadcaster: 'reverb' });
        expect(realtimeEnabled).toBe(true);
    });

    /**
     * The published-image path: no build-time key, but the server supplied
     * one at runtime. The endpoint is derived from the page origin because
     * nginx proxies the Pusher protocol's /app path through to Reverb —
     * REVERB_HOST/REVERB_PORT describe where Reverb binds (127.0.0.1:8081),
     * which no browser can reach.
     */
    it('uses a runtime key from the server and connects to this origin', async () => {
        const { configureEcho, realtimeEnabled } = await bootWith(
            undefined,
            'runtime-key',
        );

        expect(realtimeEnabled).toBe(true);
        expect(configureEcho).toHaveBeenCalledWith(
            expect.objectContaining({
                broadcaster: 'reverb',
                key: 'runtime-key',
                wsHost: window.location.hostname,
                forceTLS: window.location.protocol === 'https:',
            }),
        );
    });

    it('ignores a blank runtime key', async () => {
        const { configureEcho, realtimeEnabled } = await bootWith(
            undefined,
            '  ',
        );

        expect(configureEcho).toHaveBeenCalledWith({ broadcaster: 'null' });
        expect(realtimeEnabled).toBe(false);
    });

    it('prefers the build-time key over a runtime one', async () => {
        const { configureEcho } = await bootWith('local-key', 'runtime-key');

        // `npm run dev` runs Reverb standalone on its own host/port, not
        // behind a proxy, so the page origin would be the wrong endpoint.
        expect(configureEcho).toHaveBeenCalledWith({ broadcaster: 'reverb' });
    });
});
