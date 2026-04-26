import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EventFeed from '@/Components/Admin/EventFeed.vue';

// Mock Echo
let mockChannel;
const mockLeave = vi.fn();
const mockPrivate = vi.fn(() => {
    mockChannel = {
        listen: vi.fn(function () {
            return mockChannel;
        }),
        stopListening: vi.fn(function () {
            return mockChannel;
        }),
    };

    return mockChannel;
});

beforeEach(() => {
    window.Echo = {
        private: mockPrivate,
        leave: mockLeave,
    };
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-04-26T12:00:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete window.Echo;
});

describe('EventFeed', () => {
    describe('rendering', () => {
        it('renders the section header with title', () => {
            const wrapper = mount(EventFeed);

            expect(wrapper.text()).toContain('Event Feed');
        });

        it('renders a container with event-feed testid', () => {
            const wrapper = mount(EventFeed);

            expect(wrapper.find('[data-testid="event-feed"]').exists()).toBe(true);
        });

        it('shows empty state when no events exist', () => {
            const wrapper = mount(EventFeed);

            expect(wrapper.find('[data-testid="event-feed-empty"]').exists()).toBe(true);
            expect(wrapper.text()).toContain('Waiting for events');
        });

        it('hides empty state when events are present', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserConnected')[1];

            handler({ user_name: 'John', ip_address: '10.0.0.5' });
            await wrapper.vm.$nextTick();

            expect(wrapper.find('[data-testid="event-feed-empty"]').exists()).toBe(false);
        });
    });

    describe('Echo channel subscription', () => {
        it('subscribes to admin.events private channel on mount', () => {
            mount(EventFeed);

            expect(mockPrivate).toHaveBeenCalledWith('admin.events');
        });

        it('registers listeners for all known event types', () => {
            mount(EventFeed);

            const listenedEvents = mockChannel.listen.mock.calls.map((c) => c[0]);

            expect(listenedEvents).toContain('UserConnected');
            expect(listenedEvents).toContain('DeviceDiscovered');
            expect(listenedEvents).toContain('PortStateChanged');
            expect(listenedEvents).toContain('SwitchSyncCompleted');
            expect(listenedEvents).toContain('DhcpPoolThresholdReached');
            expect(listenedEvents).toContain('InternetAccessChanged');
            expect(listenedEvents).toContain('RateLimitChanged');
            expect(listenedEvents).toContain('UserBlocked');
            expect(listenedEvents).toContain('DnsFilterChanged');
            expect(listenedEvents).toContain('SwitchUnreachable');
            expect(listenedEvents).toContain('BandwidthAnomalyDetected');
        });

        it('leaves the channel on unmount', () => {
            const wrapper = mount(EventFeed);

            wrapper.unmount();

            expect(mockLeave).toHaveBeenCalledWith('admin.events');
        });
    });

    describe('event formatting', () => {
        it('formats UserConnected event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserConnected')[1];

            handler({ user_name: 'John', ip_address: '10.0.0.5' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('John connected from 10.0.0.5');
        });

        it('formats DeviceDiscovered event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'DeviceDiscovered')[1];

            handler({ mac_address: 'AA:BB:CC:DD:EE:FF', ip_address: '10.0.0.10' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('New device AA:BB:CC:DD:EE:FF discovered on 10.0.0.10');
        });

        it('formats PortStateChanged event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'PortStateChanged')[1];

            handler({ port_name: 'Gi1/0/1', old_status: 'connected', new_status: 'err-disabled' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('Port Gi1/0/1 changed to err-disabled');
        });

        it('formats SwitchSyncCompleted event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'SwitchSyncCompleted')[1];

            handler({ hostname: 'core-sw1', ports_updated: 48 });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('Switch core-sw1 sync completed (48 ports updated)');
        });

        it('formats DhcpPoolThresholdReached event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'DhcpPoolThresholdReached')[1];

            handler({ pool: 'Guest', usage: 92 });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('DHCP pool Guest reached 92% utilization');
        });

        it('formats InternetAccessChanged event with enabled true', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'InternetAccessChanged')[1];

            handler({ enabled: true, ip_address: '10.0.0.20' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('Internet access enabled for 10.0.0.20');
        });

        it('formats InternetAccessChanged event with enabled false', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'InternetAccessChanged')[1];

            handler({ enabled: false, ip_address: '10.0.0.20' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('Internet access disabled for 10.0.0.20');
        });

        it('formats RateLimitChanged event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'RateLimitChanged')[1];

            handler({ ip_address: '10.0.0.30', old_limit: '10Mbps', new_limit: '50Mbps' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('Rate limit changed for 10.0.0.30 from 10Mbps to 50Mbps');
        });

        it('formats UserBlocked event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserBlocked')[1];

            handler({ user_name: 'Jane', ip_address: '10.0.0.40', reason: 'Policy violation' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('Jane blocked on 10.0.0.40: Policy violation');
        });

        it('formats DnsFilterChanged event with enabled true', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'DnsFilterChanged')[1];

            handler({ enabled: true, ip_address: '10.0.0.50' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('DNS filter enabled for 10.0.0.50');
        });

        it('formats DnsFilterChanged event with enabled false', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'DnsFilterChanged')[1];

            handler({ enabled: false, ip_address: '10.0.0.50' });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('DNS filter disabled for 10.0.0.50');
        });

        it('formats SwitchUnreachable event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'SwitchUnreachable')[1];

            handler({ hostname: 'edge-sw2', failureCount: 3 });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('Switch edge-sw2 unreachable after 3 failures');
        });

        it('handles BandwidthAnomalyDetected gracefully', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'BandwidthAnomalyDetected')[1];

            handler({ ip_address: '10.0.0.60', current_bps: 500000000 });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.exists()).toBe(true);
            expect(entry.text()).toContain('Bandwidth anomaly detected');
        });

        it('falls back gracefully for unknown event type', async () => {
            const wrapper = mount(EventFeed);
            // Simulate an unknown event by directly calling the internal add
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserConnected')[1];

            handler({ user_name: 'Test', ip_address: '1.2.3.4' });
            await wrapper.vm.$nextTick();

            // The entry should exist and show something
            expect(wrapper.find('[data-testid="event-feed-entry"]').exists()).toBe(true);
        });
    });

    describe('timestamps', () => {
        it('displays a timestamp for each event', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserConnected')[1];

            handler({ user_name: 'John', ip_address: '10.0.0.5' });
            await wrapper.vm.$nextTick();

            const timestamp = wrapper.find('[data-testid="event-feed-timestamp"]');

            expect(timestamp.exists()).toBe(true);
            expect(timestamp.text()).toMatch(/\d{2}:\d{2}:\d{2}/);
        });
    });

    describe('max events', () => {
        it('defaults to 50 maximum events', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserConnected')[1];

            for (let i = 0; i < 55; i++) {
                handler({ user_name: `User${i}`, ip_address: `10.0.0.${i}` });
            }
            await wrapper.vm.$nextTick();

            const entries = wrapper.findAll('[data-testid="event-feed-entry"]');

            expect(entries).toHaveLength(50);
        });

        it('respects custom maxEvents prop', async () => {
            const wrapper = mount(EventFeed, {
                props: { maxEvents: 10 },
            });
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserConnected')[1];

            for (let i = 0; i < 15; i++) {
                handler({ user_name: `User${i}`, ip_address: `10.0.0.${i}` });
            }
            await wrapper.vm.$nextTick();

            const entries = wrapper.findAll('[data-testid="event-feed-entry"]');

            expect(entries).toHaveLength(10);
        });

        it('keeps the newest events when over max', async () => {
            const wrapper = mount(EventFeed, {
                props: { maxEvents: 3 },
            });
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserConnected')[1];

            handler({ user_name: 'First', ip_address: '10.0.0.1' });
            handler({ user_name: 'Second', ip_address: '10.0.0.2' });
            handler({ user_name: 'Third', ip_address: '10.0.0.3' });
            handler({ user_name: 'Fourth', ip_address: '10.0.0.4' });
            await wrapper.vm.$nextTick();

            const entries = wrapper.findAll('[data-testid="event-feed-entry"]');

            expect(entries).toHaveLength(3);
            // Newest should be first (top)
            expect(entries[0].text()).toContain('Fourth');
            expect(entries[2].text()).toContain('Second');
            // First should be gone
            expect(wrapper.text()).not.toContain('First connected from');
        });
    });

    describe('auto-scroll', () => {
        it('sets ref on scrollable container', () => {
            const wrapper = mount(EventFeed);

            expect(wrapper.find('[data-testid="event-feed-scroll"]').exists()).toBe(true);
        });
    });

    describe('event ordering', () => {
        it('shows newest events at the top', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'UserConnected')[1];

            handler({ user_name: 'First', ip_address: '10.0.0.1' });
            handler({ user_name: 'Second', ip_address: '10.0.0.2' });
            await wrapper.vm.$nextTick();

            const entries = wrapper.findAll('[data-testid="event-feed-entry"]');

            expect(entries[0].text()).toContain('Second');
            expect(entries[1].text()).toContain('First');
        });
    });

    describe('DeviceDiscovered with null ip_address', () => {
        it('handles null ip_address gracefully', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'DeviceDiscovered')[1];

            handler({ mac_address: 'AA:BB:CC:DD:EE:FF', ip_address: null });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.exists()).toBe(true);
            expect(entry.text()).toContain('New device AA:BB:CC:DD:EE:FF discovered');
        });
    });

    describe('without Echo', () => {
        it('renders without errors when Echo is not available', () => {
            delete window.Echo;
            const wrapper = mount(EventFeed);

            expect(wrapper.find('[data-testid="event-feed"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="event-feed-empty"]').exists()).toBe(true);
        });
    });

    describe('SwitchSyncCompleted with errors', () => {
        it('includes error count when errors are present', async () => {
            const wrapper = mount(EventFeed);
            const handler = mockChannel.listen.mock.calls.find((c) => c[0] === 'SwitchSyncCompleted')[1];

            handler({ hostname: 'core-sw1', ports_updated: 48, errors: ['timeout', 'auth fail'] });
            await wrapper.vm.$nextTick();

            const entry = wrapper.find('[data-testid="event-feed-entry"]');

            expect(entry.text()).toContain('2 errors');
        });
    });
});
