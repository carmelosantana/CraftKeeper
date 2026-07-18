import { useConnectionStatus } from '@laravel/echo-react';

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
    const status = useConnectionStatus();

    if (status === 'connected') {
        return 'connected';
    }

    if (status === 'connecting' || status === 'reconnecting') {
        return 'connecting';
    }

    return 'unavailable';
}
