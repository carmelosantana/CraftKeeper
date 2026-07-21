import { configureEcho, echoIsConfigured } from '@laravel/echo-react';

/**
 * Task 12 is the first frontend consumer of Reverb (server.console,
 * operations.{id} — see docs/architecture/decisions.md's Task 5 entry,
 * which forecast this). `configureEcho({ broadcaster: 'reverb' })` reads
 * `VITE_REVERB_APP_KEY`/`VITE_REVERB_HOST`/`VITE_REVERB_PORT`/
 * `VITE_REVERB_SCHEME` from `import.meta.env` automatically — see
 * `@laravel/echo-react`'s own defaults. Configuring Echo does NOT open a
 * socket by itself (that only happens lazily, on the first `useEcho()`/
 * `useChannel()` call from a mounted component) — calling this once, here,
 * is required before any of those hooks run, or they throw synchronously
 * ("Echo has not been configured").
 *
 * `VITE_*` values are inlined by Vite at BUILD time, and the container image
 * builds with no `.env` at all, so in the published image every
 * `VITE_REVERB_*` is `undefined`. Reverb's connector passes that key
 * straight to Pusher, whose constructor throws "You must pass your app key
 * when you instantiate Pusher." — synchronously, during render, before React
 * has committed anything. The result was a completely blank page on every
 * route that reads realtime status: Assistant, the Console, and any page
 * rendering OperationProgress.
 *
 * decisions.md (Task 12) anticipated the missing build-time wiring but
 * recorded that it would "degrade to 'unavailable' in production too, safely
 * (never a crash, never fabricated data)". The first half of that was wrong
 * — it crashed. This is what makes it true.
 *
 * With no key present we hand Echo its own `null` broadcaster instead: the
 * hooks keep working, every channel is an inert no-op, and nothing attempts
 * to open a socket. `realtimeEnabled` records which way it went, because
 * that broadcaster reports its connection as "connected" — see
 * `resources/js/hooks/use-realtime-status.ts` for why that answer must never
 * reach the UI.
 */
function reverbKey(): string {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    return typeof key === 'string' ? key.trim() : '';
}

/**
 * Whether this build carries real Reverb credentials. False in the published
 * container image today — see the note above, and the disclosed gap in
 * `docs/architecture/decisions.md` about threading `REVERB_*` through the
 * Docker build.
 */
export const realtimeEnabled: boolean = reverbKey() !== '';

let configured = false;

export function bootEcho(): void {
    if (configured || echoIsConfigured()) {
        configured = true;

        return;
    }

    configureEcho(
        realtimeEnabled ? { broadcaster: 'reverb' } : { broadcaster: 'null' },
    );
    configured = true;
}
