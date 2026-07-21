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
/** A key baked in at build time — how local development is configured. */
function buildTimeKey(): string {
    const key = import.meta.env.VITE_REVERB_APP_KEY;

    return typeof key === 'string' ? key.trim() : '';
}

/**
 * A key supplied at runtime by the server, from
 * `resources/views/app.blade.php`. This is what makes realtime work in the
 * published image, where no build-time key can exist.
 */
function runtimeKey(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return (
        document
            .querySelector('meta[name="craftkeeper-reverb-key"]')
            ?.getAttribute('content')
            ?.trim() ?? ''
    );
}

/**
 * Where the browser should open the websocket when the key came from the
 * server: this page's own origin.
 *
 * Not `REVERB_HOST`/`REVERB_PORT` — those describe where the Reverb process
 * BINDS, which in the container is 127.0.0.1:8081 (see
 * docker/supervisor/supervisord.conf) and is unreachable from a browser.
 * Nginx proxies `/app` — the path the Pusher protocol connects on — through
 * to it (docker/nginx/default.conf), so the correct browser-facing endpoint
 * is simply wherever this page was served from. That also means it follows
 * any published port or reverse-proxy hostname automatically, with nothing
 * to configure.
 */
function sameOriginTransport() {
    const secure = window.location.protocol === 'https:';
    const port = window.location.port
        ? Number(window.location.port)
        : secure
          ? 443
          : 80;

    return {
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'] as ('ws' | 'wss')[],
    };
}

/** Whether this page has a usable Reverb key from either source. */
export const realtimeEnabled: boolean =
    buildTimeKey() !== '' || runtimeKey() !== '';

let configured = false;

export function bootEcho(): void {
    if (configured || echoIsConfigured()) {
        configured = true;

        return;
    }

    // Build-time configuration wins, and is left completely untouched:
    // `npm run dev` runs Reverb standalone on its own host/port
    // (REVERB_HOST/REVERB_PORT via VITE_REVERB_*), not behind a proxy, so
    // deriving the endpoint from the page origin would be wrong there.
    if (buildTimeKey() !== '') {
        configureEcho({ broadcaster: 'reverb' });
        configured = true;

        return;
    }

    const key = runtimeKey();

    configureEcho(
        key !== ''
            ? { broadcaster: 'reverb', key, ...sameOriginTransport() }
            : { broadcaster: 'null' },
    );
    configured = true;
}
