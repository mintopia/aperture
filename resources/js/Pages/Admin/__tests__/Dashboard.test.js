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
    let originalAxios;
    let originalRoute;

    beforeEach(() => {
        originalEcho = window.Echo;
        originalAxios = window.axios;
        originalRoute = window.route;
        window.route = routeMock;
        vi.useFakeTimers();
        window.axios = {
            get: vi.fn(() =>
                Promise.resolve({
                    data: {
                        timestamps: [],
                        download: [],
                        upload: [],
                        totalReceived: 0,
                        totalSent: 0,
                    },
                }),
            ),
        };
        vi.spyOn(router, 'reload').mockImplementation(() => {});
    });

    afterEach(() => {
        window.Echo = originalEcho;
        window.axios = originalAxios;
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

    it('registers listener for AuditLogRecorded', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        const channel = echo._channels['admin.events'];
        expect(channel.listen).toHaveBeenCalledWith('AuditLogRecorded', expect.any(Function));
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

    it('prepends activity item and triggers refresh on AuditLogRecorded', async () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        const wrapper = mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        router.reload.mockClear();

        const channel = echo._channels['admin.events'];
        channel._listeners['AuditLogRecorded']({
            id: 42,
            action: 'user.connected',
            description: 'Test user connected',
            severity: 'info',
            created_at: '2026-06-12T10:00:00Z',
        });

        await nextTick();

        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['totalUsers', 'onlineUsers', 'activeIps', 'blockedUsers', 'dhcpPools', 'recentUsers'],
                preserveScroll: true,
            }),
        );

        const activityWidget = wrapper.find('[data-testid="recent-activity"]');
        expect(activityWidget.exists()).toBe(true);
    });

    it('seeds recent activity from recentEvents prop on mount', async () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        const recentEvents = [
            {
                id: 1,
                action: 'user.blocked',
                description: 'Alice was blocked',
                severity: 'warning',
                created_at: '2026-06-12T09:00:00Z',
            },
            {
                id: 2,
                action: 'user.connected',
                description: 'Bob connected',
                severity: 'info',
                created_at: '2026-06-12T09:30:00Z',
            },
        ];

        const wrapper = mount(Dashboard, {
            props: { ...defaultProps, recentEvents },
            global: {
                ...globalConfig,
                stubs: globalConfig.stubs.filter((s) => s !== 'SectionHeader'),
            },
        });

        await nextTick();

        const activityWidget = wrapper.find('[data-testid="recent-activity"]');
        expect(activityWidget.exists()).toBe(true);
        const html = activityWidget.html();
        expect(html).toContain('Alice was blocked');
        expect(html).toContain('Bob connected');
    });

    it('falls back to polling when WebSocket is disconnected', () => {
        window.Echo = undefined;

        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        // Clear initial fetch call from onMounted
        window.axios.get.mockClear();

        // Advance to trigger fallback poll
        vi.advanceTimersByTime(30000);

        // The poll callback (fetchBandwidth) should have been called
        expect(window.axios.get).toHaveBeenCalled();
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
        window.axios.get.mockClear();

        // Advance well past poll interval
        await vi.advanceTimersByTimeAsync(60000);

        // Fetch should NOT have been called via polling (only initial + any health checks)
        // No polling calls expected since WebSocket is connected
        expect(window.axios.get).not.toHaveBeenCalled();
    });
});
