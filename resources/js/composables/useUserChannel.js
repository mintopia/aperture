/**
 * Composable to subscribe to a per-user private Echo channel
 * and listen for portal-relevant broadcast events.
 *
 * Provides a cleanup function to leave the channel on unmount.
 */
export function useUserChannel(userId, handlers = {}) {
    const noop = () => {};

    if (!userId || !window.Echo) {
        return { cleanup: noop };
    }

    const channelName = `user.${userId}`;
    const channel = window.Echo.private(channelName);

    if (handlers.onInternetAccessChanged) {
        channel.listen('.InternetAccessChanged', handlers.onInternetAccessChanged);
    }

    if (handlers.onRateLimitChanged) {
        channel.listen('.RateLimitChanged', handlers.onRateLimitChanged);
    }

    if (handlers.onDnsFilterChanged) {
        channel.listen('.DnsFilterChanged', handlers.onDnsFilterChanged);
    }

    if (handlers.onUserBlocked) {
        channel.listen('.UserBlocked', handlers.onUserBlocked);
    }

    function cleanup() {
        if (window.Echo) {
            window.Echo.leave(channelName);
        }
    }

    return { cleanup };
}
