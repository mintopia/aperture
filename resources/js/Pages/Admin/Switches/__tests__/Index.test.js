import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../Index.vue';

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
    switches: [
        {
            id: 1,
            name: 'Core Switch',
            hostname: 'switch-01.local',
            type: 'ios',
            enabled: true,
            port_count: 48,
            ports_up: 40,
            ports_down: 8,
            ports_error: 0,
            latest_sync_status: 'completed',
            last_synced_at: '2026-04-26T00:00:00Z',
        },
    ],
    filters: {},
};

const globalConfig = {
    stubs: ['AdminLayout', 'DataTable', 'EmptyState', 'FilterBar', 'SectionHeader', 'Link'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

describe('Switches/Index Echo integration', () => {
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

        mount(Index, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(echo.private).toHaveBeenCalledWith('admin.events');
    });

    it('registers listener for SwitchSyncCompleted event', () => {
        const pusher = createMockPusher('connected');
        const echo = createMockEcho(pusher);
        window.Echo = echo;

        mount(Index, {
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

        const wrapper = mount(Index, {
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

        const wrapper = mount(Index, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Switches');
    });
});
