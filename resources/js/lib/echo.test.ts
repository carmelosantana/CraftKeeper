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
    async function bootWith(key: string | undefined) {
        vi.resetModules();

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

    it('uses Reverb when a key really is present', async () => {
        const { configureEcho, realtimeEnabled } = await bootWith('local-key');

        expect(configureEcho).toHaveBeenCalledWith({ broadcaster: 'reverb' });
        expect(realtimeEnabled).toBe(true);
    });
});
