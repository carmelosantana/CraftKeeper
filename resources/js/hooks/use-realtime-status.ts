import { useConnectionStatus } from '@laravel/echo-react';
import { realtimeEnabled } from '@/lib/echo';

/**
 * Maps `@laravel/echo-react`'s `ConnectionStatus` ("connected" |
 * "disconnected" | "connecting" | "reconnecting" | "failed") onto the
 * small vocabulary Console/OperationProgress render — collapsing every
 * non-"connected" state into a single, honest "not currently receiving
 * live updates" signal, per ambiguity resolution #4 ("on websocket loss,
 * show a reconnect state"). This app never fabricates a "connected" state
 * it hasn't actually observed.
 */
export type RealtimeStatus = 'connected' | 'connecting' | 'unavailable';

export function useRealtimeStatus(): RealtimeStatus {
    // Called unconditionally: hook order must never depend on configuration.
    // Safe either way — when Reverb is not configured `bootEcho()` installs
    // Echo's `null` broadcaster, so this reads an inert connector instead of
    // throwing.
    const status = useConnectionStatus();

    // That null broadcaster answers "connected": it has nothing to connect
    // to and reports success regardless. Passing that through would show a
    // live "connected" indicator above a console that can never receive a
    // line — precisely the fabricated state this app refuses to display. When
    // realtime is not configured the honest answer is "unavailable", whatever
    // Echo claims.
    if (!realtimeEnabled) {
        return 'unavailable';
    }

    if (status === 'connected') {
        return 'connected';
    }

    if (status === 'connecting' || status === 'reconnecting') {
        return 'connecting';
    }

    return 'unavailable';
}
