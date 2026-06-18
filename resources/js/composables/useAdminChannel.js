import { ref, onMounted, onUnmounted } from 'vue';

/**
 * Composable for subscribing to the admin.events private channel via Laravel Echo.
 * Listens for broadcast events and invokes registered callbacks.
 * Falls back to polling when WebSocket is disconnected.
 *
 * @param {Object} options
 * @param {Object.<string, Function>} options.events - Map of event class names to handler callbacks
 * @param {Function|null} options.poll - Optional polling function to call as fallback
 * @param {number} options.pollInterval - Polling interval in ms (default 30000)
 * @returns {{ connected: import('vue').Ref<boolean>, leave: () => void }}
 */
export function useAdminChannel({ events = {}, poll = null, pollInterval = 30000 } = {}) {
    const connected = ref(false);
    let channel = null;
    let fallbackTimer = null;
    let connectionCheckTimer = null;

    function startFallbackPolling() {
        stopFallbackPolling();
        if (typeof poll === 'function') {
            fallbackTimer = setInterval(poll, pollInterval);
        }
    }

    function stopFallbackPolling() {
        if (fallbackTimer) {
            clearInterval(fallbackTimer);
            fallbackTimer = null;
        }
    }

    function checkConnection() {
        if (!window.Echo) {
            if (connected.value) {
                connected.value = false;
                startFallbackPolling();
            }
            return;
        }

        const connector = window.Echo.connector;
        const pusher = connector?.pusher;

        if (pusher && typeof pusher.connection?.state === 'string') {
            const state = pusher.connection.state;
            const wasConnected = connected.value;
            connected.value = state === 'connected';

            if (wasConnected && !connected.value) {
                startFallbackPolling();
            } else if (!wasConnected && connected.value) {
                stopFallbackPolling();
            }
        }
    }

    function subscribe() {
        if (!window.Echo) {
            connected.value = false;
            startFallbackPolling();
            return;
        }

        try {
            channel = window.Echo.private('admin.events');

            for (const [eventName, handler] of Object.entries(events)) {
                channel.listen(eventName, handler);
            }

            const pusher = window.Echo.connector?.pusher;

            if (pusher) {
                pusher.connection?.bind('connected', () => {
                    connected.value = true;
                    stopFallbackPolling();
                });

                pusher.connection?.bind('disconnected', () => {
                    connected.value = false;
                    startFallbackPolling();
                });

                pusher.connection?.bind('unavailable', () => {
                    connected.value = false;
                    startFallbackPolling();
                });

                // Check initial state
                if (pusher.connection?.state === 'connected') {
                    connected.value = true;
                } else {
                    connected.value = false;
                    startFallbackPolling();
                }
            } else {
                // No pusher available, assume connected (for test environments)
                connected.value = true;
            }

            // Periodic connection health check
            connectionCheckTimer = setInterval(checkConnection, 10000);
        } catch {
            connected.value = false;
            startFallbackPolling();
        }
    }

    function leave() {
        stopFallbackPolling();

        if (connectionCheckTimer) {
            clearInterval(connectionCheckTimer);
            connectionCheckTimer = null;
        }

        if (channel && window.Echo) {
            window.Echo.leave('admin.events');
            channel = null;
        }

        connected.value = false;
    }

    onMounted(() => {
        subscribe();
    });

    onUnmounted(() => {
        leave();
    });

    return { connected, leave };
}
