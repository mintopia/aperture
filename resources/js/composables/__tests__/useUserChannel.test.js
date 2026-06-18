import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useUserChannel } from '../useUserChannel.js';

describe('useUserChannel', () => {
    let mockChannel;
    let mockEcho;

    beforeEach(() => {
        mockChannel = {
            listen: vi.fn().mockReturnThis(),
            stopListening: vi.fn().mockReturnThis(),
        };

        mockEcho = {
            private: vi.fn().mockReturnValue(mockChannel),
            leave: vi.fn(),
        };

        window.Echo = mockEcho;
    });

    afterEach(() => {
        delete window.Echo;
    });

    it('subscribes to the private user channel', () => {
        const handlers = {};
        useUserChannel(42, handlers);

        expect(mockEcho.private).toHaveBeenCalledWith('user.42');
    });

    it('registers InternetAccessChanged listener when handler provided', () => {
        const onInternetAccessChanged = vi.fn();
        useUserChannel(1, { onInternetAccessChanged });

        expect(mockChannel.listen).toHaveBeenCalledWith('.InternetAccessChanged', expect.any(Function));
    });

    it('registers RateLimitChanged listener when handler provided', () => {
        const onRateLimitChanged = vi.fn();
        useUserChannel(1, { onRateLimitChanged });

        expect(mockChannel.listen).toHaveBeenCalledWith('.RateLimitChanged', expect.any(Function));
    });

    it('registers DnsFilterChanged listener when handler provided', () => {
        const onDnsFilterChanged = vi.fn();
        useUserChannel(1, { onDnsFilterChanged });

        expect(mockChannel.listen).toHaveBeenCalledWith('.DnsFilterChanged', expect.any(Function));
    });

    it('registers UserBlocked listener when handler provided', () => {
        const onUserBlocked = vi.fn();
        useUserChannel(1, { onUserBlocked });

        expect(mockChannel.listen).toHaveBeenCalledWith('.UserBlocked', expect.any(Function));
    });

    it('calls the handler when an event is received', () => {
        const onInternetAccessChanged = vi.fn();
        useUserChannel(1, { onInternetAccessChanged });

        const listenCall = mockChannel.listen.mock.calls.find((c) => c[0] === '.InternetAccessChanged');
        const callback = listenCall[1];

        const eventData = { ip_address_id: 5, enabled: true };
        callback(eventData);

        expect(onInternetAccessChanged).toHaveBeenCalledWith(eventData);
    });

    it('does not register listeners for unspecified handlers', () => {
        useUserChannel(1, {});

        expect(mockChannel.listen).not.toHaveBeenCalled();
    });

    it('returns a cleanup function that leaves the channel', () => {
        const { cleanup } = useUserChannel(1, { onInternetAccessChanged: vi.fn() });

        cleanup();

        expect(mockEcho.leave).toHaveBeenCalledWith('user.1');
    });

    it('does not subscribe when Echo is unavailable', () => {
        delete window.Echo;

        const { cleanup } = useUserChannel(1, { onInternetAccessChanged: vi.fn() });

        // Should not throw and cleanup should be safe to call
        expect(cleanup).toBeInstanceOf(Function);
        cleanup();
    });

    it('does not subscribe when userId is null', () => {
        const { cleanup } = useUserChannel(null, { onInternetAccessChanged: vi.fn() });

        expect(mockEcho.private).not.toHaveBeenCalled();
        expect(cleanup).toBeInstanceOf(Function);
    });

    it('registers all four listeners when all handlers provided', () => {
        useUserChannel(1, {
            onInternetAccessChanged: vi.fn(),
            onRateLimitChanged: vi.fn(),
            onDnsFilterChanged: vi.fn(),
            onUserBlocked: vi.fn(),
        });

        expect(mockChannel.listen).toHaveBeenCalledTimes(4);
    });
});
