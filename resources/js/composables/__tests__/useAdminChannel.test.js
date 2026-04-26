import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, nextTick } from 'vue';
import { useAdminChannel } from '../useAdminChannel';

function createMockPusher(initialState = 'connected') {
    const bindings = {};
    return {
        connection: {
            state: initialState,
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
            const listeners = {};
            const channel = {
                listen: vi.fn((event, handler) => {
                    listeners[event] = handler;
                    return channel;
                }),
                _listeners: listeners,
            };
            channels[channelName] = channel;
            return channel;
        }),
        leave: vi.fn(),
        _channels: channels,
    };
}

function createTestComponent(options = {}) {
    return defineComponent({
        setup() {
            const result = useAdminChannel(options);
            return { ...result };
        },
        template: '<div></div>',
    });
}

describe('useAdminChannel', () => {
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

    describe('channel subscription', () => {
        it('subscribes to private admin.events channel on mount', () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                }),
            );

            expect(echo.private).toHaveBeenCalledWith('admin.events');
        });

        it('registers event listeners for all provided events', () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const handler1 = vi.fn();
            const handler2 = vi.fn();

            mount(
                createTestComponent({
                    events: {
                        SwitchSyncCompleted: handler1,
                        PortStateChanged: handler2,
                    },
                }),
            );

            const channel = echo._channels['admin.events'];
            expect(channel.listen).toHaveBeenCalledWith('SwitchSyncCompleted', handler1);
            expect(channel.listen).toHaveBeenCalledWith('PortStateChanged', handler2);
        });

        it('sets connected to true when pusher state is connected', () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                }),
            );

            expect(wrapper.vm.connected).toBe(true);
        });

        it('sets connected to false when pusher state is not connected', () => {
            const pusher = createMockPusher('connecting');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                }),
            );

            expect(wrapper.vm.connected).toBe(false);
        });

        it('leaves channel on unmount', () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                }),
            );

            wrapper.unmount();

            expect(echo.leave).toHaveBeenCalledWith('admin.events');
        });
    });

    describe('connection state transitions', () => {
        it('updates connected to true on pusher connected event', async () => {
            const pusher = createMockPusher('connecting');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                }),
            );

            expect(wrapper.vm.connected).toBe(false);

            // Simulate pusher connecting
            pusher._bindings['connected']();
            await nextTick();

            expect(wrapper.vm.connected).toBe(true);
        });

        it('updates connected to false on pusher disconnected event', async () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                }),
            );

            expect(wrapper.vm.connected).toBe(true);

            // Simulate disconnection
            pusher._bindings['disconnected']();
            await nextTick();

            expect(wrapper.vm.connected).toBe(false);
        });

        it('updates connected to false on pusher unavailable event', async () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                }),
            );

            expect(wrapper.vm.connected).toBe(true);

            pusher._bindings['unavailable']();
            await nextTick();

            expect(wrapper.vm.connected).toBe(false);
        });
    });

    describe('fallback polling', () => {
        it('starts fallback polling when Echo is not available', () => {
            window.Echo = undefined;
            const poll = vi.fn();

            mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                    poll,
                    pollInterval: 30000,
                }),
            );

            expect(poll).not.toHaveBeenCalled();

            vi.advanceTimersByTime(30000);
            expect(poll).toHaveBeenCalledTimes(1);

            vi.advanceTimersByTime(30000);
            expect(poll).toHaveBeenCalledTimes(2);
        });

        it('starts fallback polling when initially disconnected', () => {
            const pusher = createMockPusher('connecting');
            const echo = createMockEcho(pusher);
            window.Echo = echo;
            const poll = vi.fn();

            mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                    poll,
                    pollInterval: 15000,
                }),
            );

            expect(poll).not.toHaveBeenCalled();

            vi.advanceTimersByTime(15000);
            expect(poll).toHaveBeenCalledTimes(1);
        });

        it('does not poll when connected via WebSocket', () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;
            const poll = vi.fn();

            mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                    poll,
                    pollInterval: 5000,
                }),
            );

            vi.advanceTimersByTime(60000);
            expect(poll).not.toHaveBeenCalled();
        });

        it('starts polling when connection drops', async () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;
            const poll = vi.fn();

            mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                    poll,
                    pollInterval: 10000,
                }),
            );

            // Initially connected - no polling
            vi.advanceTimersByTime(9999);
            expect(poll).not.toHaveBeenCalled();

            // Simulate disconnection (also update mock state for health checks)
            pusher.connection.state = 'disconnected';
            pusher._bindings['disconnected']();
            await nextTick();

            // Now polling should start
            vi.advanceTimersByTime(10000);
            expect(poll).toHaveBeenCalledTimes(1);
        });

        it('stops polling when connection is restored', async () => {
            const pusher = createMockPusher('connecting');
            const echo = createMockEcho(pusher);
            window.Echo = echo;
            const poll = vi.fn();

            mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                    poll,
                    pollInterval: 10000,
                }),
            );

            // Polling active while disconnected
            vi.advanceTimersByTime(10000);
            expect(poll).toHaveBeenCalledTimes(1);

            // Simulate connection (also update mock state for health checks)
            pusher.connection.state = 'connected';
            pusher._bindings['connected']();
            await nextTick();

            // Polling should have stopped
            poll.mockClear();
            vi.advanceTimersByTime(30000);
            expect(poll).not.toHaveBeenCalled();
        });

        it('stops polling on unmount', () => {
            window.Echo = undefined;
            const poll = vi.fn();

            const wrapper = mount(
                createTestComponent({
                    events: {},
                    poll,
                    pollInterval: 5000,
                }),
            );

            vi.advanceTimersByTime(5000);
            expect(poll).toHaveBeenCalledTimes(1);

            wrapper.unmount();

            poll.mockClear();
            vi.advanceTimersByTime(15000);
            expect(poll).not.toHaveBeenCalled();
        });

        it('uses custom poll interval', () => {
            window.Echo = undefined;
            const poll = vi.fn();

            mount(
                createTestComponent({
                    events: {},
                    poll,
                    pollInterval: 5000,
                }),
            );

            vi.advanceTimersByTime(4999);
            expect(poll).not.toHaveBeenCalled();

            vi.advanceTimersByTime(1);
            expect(poll).toHaveBeenCalledTimes(1);
        });

        it('does not start polling if no poll function provided', () => {
            window.Echo = undefined;

            // Should not throw even without poll function
            const wrapper = mount(
                createTestComponent({
                    events: {},
                }),
            );

            vi.advanceTimersByTime(60000);
            expect(wrapper.vm.connected).toBe(false);
        });
    });

    describe('event handling', () => {
        it('invokes event handler when event is received', () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const handler = vi.fn();
            mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: handler },
                }),
            );

            const channel = echo._channels['admin.events'];
            const registeredHandler = channel._listeners['SwitchSyncCompleted'];

            const payload = {
                switch_config_id: 1,
                hostname: 'switch-01.local',
                ports_updated: 5,
                errors: [],
            };

            registeredHandler(payload);

            expect(handler).toHaveBeenCalledWith(payload);
        });

        it('handles multiple events independently', () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const switchHandler = vi.fn();
            const portHandler = vi.fn();

            mount(
                createTestComponent({
                    events: {
                        SwitchSyncCompleted: switchHandler,
                        PortStateChanged: portHandler,
                    },
                }),
            );

            const channel = echo._channels['admin.events'];

            channel._listeners['SwitchSyncCompleted']({
                switch_config_id: 1,
                hostname: 'switch-01.local',
                ports_updated: 5,
                errors: [],
            });

            channel._listeners['PortStateChanged']({
                switch_port_id: 42,
                port_name: 'GigabitEthernet0/1',
                old_status: 'up',
                new_status: 'down',
            });

            expect(switchHandler).toHaveBeenCalledTimes(1);
            expect(portHandler).toHaveBeenCalledTimes(1);
        });
    });

    describe('leave function', () => {
        it('can be called manually to unsubscribe', () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                }),
            );

            expect(wrapper.vm.connected).toBe(true);

            wrapper.vm.leave();

            expect(echo.leave).toHaveBeenCalledWith('admin.events');
            expect(wrapper.vm.connected).toBe(false);
        });

        it('stops fallback polling when leave is called', () => {
            window.Echo = undefined;
            const poll = vi.fn();

            const wrapper = mount(
                createTestComponent({
                    events: {},
                    poll,
                    pollInterval: 5000,
                }),
            );

            vi.advanceTimersByTime(5000);
            expect(poll).toHaveBeenCalledTimes(1);

            wrapper.vm.leave();
            poll.mockClear();
            vi.advanceTimersByTime(15000);
            expect(poll).not.toHaveBeenCalled();
        });
    });

    describe('error handling', () => {
        it('falls back to polling if Echo.private throws', () => {
            window.Echo = {
                connector: { pusher: createMockPusher('connected') },
                private: vi.fn(() => {
                    throw new Error('Channel subscription failed');
                }),
                leave: vi.fn(),
            };
            const poll = vi.fn();

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                    poll,
                    pollInterval: 10000,
                }),
            );

            expect(wrapper.vm.connected).toBe(false);

            vi.advanceTimersByTime(10000);
            expect(poll).toHaveBeenCalledTimes(1);
        });
    });

    describe('periodic health check', () => {
        it('detects connection drop via health check', async () => {
            const pusher = createMockPusher('connected');
            const echo = createMockEcho(pusher);
            window.Echo = echo;
            const poll = vi.fn();

            const wrapper = mount(
                createTestComponent({
                    events: { SwitchSyncCompleted: vi.fn() },
                    poll,
                    pollInterval: 5000,
                }),
            );

            expect(wrapper.vm.connected).toBe(true);

            // Simulate state change without event
            pusher.connection.state = 'disconnected';

            // Trigger health check (every 10s)
            vi.advanceTimersByTime(10000);
            await nextTick();

            expect(wrapper.vm.connected).toBe(false);

            // Fallback polling should now be active
            vi.advanceTimersByTime(5000);
            expect(poll).toHaveBeenCalledTimes(1);
        });
    });
});
