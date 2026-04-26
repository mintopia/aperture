import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import Dashboard from '../Dashboard.vue';

const routeMock = vi.fn((name, params) => `/${name}${params ? '/' + params : ''}`);

function createMockPusher(state = 'connected') {
    const bindings = {};
    return {
        connection: {
            state,
            bind: vi.fn((event, handler) => {
                bindings[event] = handler;
            }),
        },
        _bindings: bindings,
    };
}

function createMockEcho(pusher) {
    const channels = {};
    return {
        connector: { pusher },
        private: vi.fn((channelName) => {
            const channel = {
                listen: vi.fn(() => channel),
                _listeners: {},
            };
            channel.listen = vi.fn((event, handler) => {
                channel._listeners[event] = handler;
                return channel;
            });
            channels[channelName] = channel;
            return channel;
        }),
        leave: vi.fn(),
        _channels: channels,
    };
}

const defaultProps = {
    totalUsers: 50,
    onlineUsers: 25,
    activeIps: 30,
    blockedUsers: 2,
    dhcpPools: [],
    recentUsers: { data: [] },
};

const globalConfig = {
    stubs: [
        'AdminLayout',
        'DhcpPoolsCard',
        'DataTable',
        'EmptyState',
        'Pagination',
        'SectionHeader',
        'StatCard',
        'TimeSeriesChart',
        'Deferred',
        'Link',
    ],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

describe('Dashboard Echo integration', () => {
    let originalEcho;
    let originalFetch;
    let originalRoute;

    beforeEach(() => {
        originalEcho = window.Echo;
        originalFetch = window.fetch;
        originalRoute = window.route;
        window.route = routeMock;
        vi.useFakeTimers();
        window.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        timestamps: [],
                        download: [],
                        upload: [],
                        totalReceived: 0,
                        totalSent: 0,
                    }),
            }),
        );
        vi.spyOn(router, 'reload').mockImplementation(() => {});
    });

    afterEach(() => {
        window.Echo = originalEcho;
        window.fetch = originalFetch;
        window.route = originalRoute;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('subscribes to admin.events channel on mount', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(echo.private).toHaveBeenCalledWith('admin.events');
    });

    it('registers listeners for UserConnected, DeviceDiscovered, and DhcpPoolThresholdReached', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        const channel = echo._channels['admin.events'];
        expect(channel.listen).toHaveBeenCalledWith('UserConnected', expect.any(Function));
        expect(channel.listen).toHaveBeenCalledWith('DeviceDiscovered', expect.any(Function));
        expect(channel.listen).toHaveBeenCalledWith('DhcpPoolThresholdReached', expect.any(Function));
    });

    it('leaves channel on unmount', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        const wrapper = mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        wrapper.unmount();

        expect(echo.leave).toHaveBeenCalledWith('admin.events');
    });

    it('triggers refresh on UserConnected event', async () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        router.reload.mockClear();

        const channel = echo._channels['admin.events'];
        channel._listeners['UserConnected']({
            user_id: 1,
            user_name: 'Test User',
            ip_address_id: 1,
            ip_address: '10.0.0.1',
            mac_address_id: 1,
            mac_address: 'AA:BB:CC:DD:EE:FF',
        });

        await nextTick();

        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['totalUsers', 'onlineUsers', 'activeIps', 'blockedUsers', 'dhcpPools', 'recentUsers'],
                preserveScroll: true,
            }),
        );
    });

    it('falls back to polling when WebSocket is disconnected', () => {
        window.Echo = undefined;

        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        // Clear initial fetch call from onMounted
        window.fetch.mockClear();

        // Advance to trigger fallback poll
        vi.advanceTimersByTime(30000);

        // The poll callback (fetchBandwidth) should have been called
        expect(window.fetch).toHaveBeenCalled();
    });

    it('does not poll when WebSocket is connected', async () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        // Clear initial fetch call
        await vi.advanceTimersByTimeAsync(0);
        window.fetch.mockClear();

        // Advance well past poll interval
        await vi.advanceTimersByTimeAsync(60000);

        // Fetch should NOT have been called via polling (only initial + any health checks)
        // No polling calls expected since WebSocket is connected
        expect(window.fetch).not.toHaveBeenCalled();
    });
});
