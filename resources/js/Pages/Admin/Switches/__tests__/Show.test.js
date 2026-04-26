import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '../Show.vue';

const routeMock = vi.fn((name, params) => `/${name}${params ? '/' + JSON.stringify(params) : ''}`);

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
                _listeners: {},
                listen: vi.fn((event, handler) => {
                    channel._listeners[event] = handler;
                    return channel;
                }),
            };
            channels[channelName] = channel;
            return channel;
        }),
        leave: vi.fn(),
        _channels: channels,
    };
}

const defaultProps = {
    switchConfig: {
        id: 1,
        name: 'Core Switch',
        hostname: 'switch-01.local',
        port: 22,
        type: 'ios',
        enabled: true,
        timeout: 30,
        last_synced_at: '2026-04-26T00:00:00Z',
        created_at: '2026-01-01T00:00:00Z',
    },
    ports: [
        {
            interface: 'GigabitEthernet0/1',
            description: 'Server 1',
            admin_status: 'up',
            status: 'connected',
            speed: 1000,
            vlan: 10,
            switchport_mode: 'access',
            poe: 'enabled',
        },
    ],
    canDownloadConfig: false,
    latestSync: null,
    runningConfig: null,
};

const globalConfig = {
    stubs: ['AdminLayout', 'MetadataStrip', 'DataTable', 'ConfigBlock', 'Link'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

describe('Switches/Show Echo integration', () => {
    let originalEcho;

    beforeEach(() => {
        originalEcho = window.Echo;
        vi.useFakeTimers();
    });

    afterEach(() => {
        window.Echo = originalEcho;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('subscribes to admin.events channel on mount', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Show, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(echo.private).toHaveBeenCalledWith('admin.events');
    });

    it('registers listener for SwitchSyncCompleted event', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Show, {
            props: defaultProps,
            global: globalConfig,
        });

        const channel = echo._channels['admin.events'];
        expect(channel.listen).toHaveBeenCalledWith('SwitchSyncCompleted', expect.any(Function));
    });

    it('registers listener for PortStateChanged event', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Show, {
            props: defaultProps,
            global: globalConfig,
        });

        const channel = echo._channels['admin.events'];
        expect(channel.listen).toHaveBeenCalledWith('PortStateChanged', expect.any(Function));
    });

    it('leaves channel on unmount', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        const wrapper = mount(Show, {
            props: defaultProps,
            global: globalConfig,
        });

        wrapper.unmount();

        expect(echo.leave).toHaveBeenCalledWith('admin.events');
    });

    it('renders page title', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        const wrapper = mount(Show, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Core Switch');
    });
});
