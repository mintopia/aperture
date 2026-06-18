import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '../Show.vue';

const routeMock = vi.fn((name, params) => `/${name}/${JSON.stringify(params)}`);

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
    switchConfig: { id: 1, hostname: 'switch-01.local' },
    port: {
        interface: 'GigabitEthernet0/1',
        description: 'Test port',
        admin_status: 'up',
        status: 'connected',
        speed: '1G',
        duplex: 'full',
        vlan: 10,
        switchport_mode: 'access',
        poe: 'enabled',
        last_synced_at: '2026-04-26T00:00:00Z',
        interface_output: null,
        config_text: null,
    },
    macs: [],
    bandwidth: {},
    errors: {},
    metricsAvailable: false,
    prevPort: null,
    nextPort: null,
};

const globalConfig = {
    stubs: [
        'AdminLayout',
        'MetadataStrip',
        'SectionHeader',
        'ConfigBlock',
        'TimeSeriesChart',
        'ConfirmModal',
        'ConnectedDevicesSummary',
        'Link',
    ],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

describe('Switches/Ports/Show Echo integration', () => {
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

    it('still runs display timer when WebSocket is connected', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        const wrapper = mount(Show, {
            props: defaultProps,
            global: globalConfig,
        });

        // Display timer should still run regardless of WebSocket state
        vi.advanceTimersByTime(5000);

        // The display timer updates the "last updated" text
        expect(wrapper.find('[data-testid="last-updated"]').exists()).toBe(true);
    });
});
