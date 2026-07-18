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
 * No supervisor in this repo runs `reverb:start` outside the Docker image
 * (docker/supervisor/supervisord.conf) — locally, in tests, and in this
 * sandbox's e2e runs there is no live Reverb server to connect to, so the
 * very first real-world exercise of this code path is: Echo tries to open
 * a websocket, it fails/never completes, and `useConnectionStatus()`
 * genuinely reports a non-"connected" state. That is exactly what Console/
 * OperationProgress's reconnect UI is built to show — see
 * `resources/js/hooks/use-realtime-status.ts`.
 */
let configured = false;

export function bootEcho(): void {
    if (configured || echoIsConfigured()) {
        configured = true;

        return;
    }

    configureEcho({ broadcaster: 'reverb' });
    configured = true;
}
