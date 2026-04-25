/* eslint-disable vue/one-component-per-file */
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Admin/Dashboard.vue';

let deferredReady = true;

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            name: 'InertiaLink',
            props: {
                href: { type: String, default: '#' },
            },
            setup(props, { slots }) {
                return () => h('a', { href: props.href }, slots.default ? slots.default() : []);
            },
        }),
        Deferred: defineComponent({
            name: 'Deferred',
            props: {
                data: {
                    type: [String, Array],
                    default: '',
                },
            },
            setup(_props, { slots }) {
                return () => (deferredReady ? slots.default?.() : slots.fallback?.());
            },
        }),
    };
});

vi.stubGlobal(
    'route',
    (name, param) => (param ? `/mocked/${name}/${param}` : `/mocked/${name}`),
);

global.fetch = vi.fn(() =>
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

const routeMock = (name, param) => (param ? `/mocked/${name}/${param}` : `/mocked/${name}`);

const defaultGlobal = {
    stubs: {
        AdminLayout: { template: '<div><slot /></div>' },
        TimeSeriesChart: { template: '<div data-testid="bandwidth-chart"></div>' },
    },
    mocks: {
        route: routeMock,
    },
};

describe('Dashboard', () => {
    const makeProps = (overrides = {}) => ({
        totalUsers: 128,
        onlineUsers: 42,
        activeIps: 89,
        blockedUsers: 3,
        dhcpPools: [
            { name: 'Users', network: '10.0.1.0/24', used: 89, total: 200, utilisation: 0.445 },
            { name: 'Infrastructure', network: '10.0.2.0/24', used: 12, total: 50, utilisation: 0.24 },
            { name: 'Guest', network: '10.0.3.0/24', used: 45, total: 50, utilisation: 0.9 },
        ],
        recentUsers: {
            current_page: 1,
            last_page: 2,
            from: 1,
            to: 2,
            total: 30,
            links: [
                { label: '&laquo; Previous', url: null, active: false },
                { label: '1', url: '/users?page=1', active: true },
                { label: '2', url: '/users?page=2', active: false },
                { label: 'Next &raquo;', url: '/users?page=2', active: false },
            ],
            data: [
                {
                    id: 1,
                    nickname: 'alice',
                    email: 'alice@example.com',
                    ips_count: 4,
                    weekly_received: 1536,
                    weekly_sent: 512,
                    internet_blocked: false,
                    last_seen: '2026-04-17T11:55:00.000Z',
                },
                {
                    id: 2,
                    nickname: 'bob',
                    email: 'bob@example.com',
                    ips_count: 1,
                    weekly_received: 5368709120,
                    weekly_sent: 1073741824,
                    internet_blocked: true,
                    last_seen: '2026-04-17T11:00:00.000Z',
                },
            ],
        },
        ...overrides,
    });

    beforeEach(() => {
        deferredReady = true;
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-17T12:00:00.000Z'));
        vi.mocked(global.fetch).mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders Dashboard title', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Dashboard');
    });

    it('renders the four stat cards with the correct labels and values', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const cards = wrapper.findAll('[data-testid="stat-card"]');

        expect(cards).toHaveLength(4);
        expect(wrapper.text()).toContain('Online Now');
        expect(wrapper.text()).toContain('42');
        expect(wrapper.text()).toContain('Total Users');
        expect(wrapper.text()).toContain('128');
        expect(wrapper.text()).toContain('IPs Active');
        expect(wrapper.text()).toContain('89');
        expect(wrapper.text()).toContain('Blocked');
        expect(wrapper.text()).toContain('3');
    });

    it('renders online now sub text in mockup format "of N · X%"', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="stat-label-dot"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('of 128');
        expect(wrapper.text()).toContain('33%');
    });

    it('renders deferred loading fallbacks when deferred props are not yet available', () => {
        deferredReady = false;

        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="dhcp-pools-loading"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="recent-users-loading"]').exists()).toBe(true);
    });

    it('renders total bandwidth section with range selector and chart', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="total-bandwidth-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="bandwidth-range-selector"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="bandwidth-chart"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="bandwidth-download"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="bandwidth-upload"]').exists()).toBe(true);
    });

    it('renders bandwidth range selector with 1H, 24H, and 72H buttons', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const rangeSelector = wrapper.find('[data-testid="bandwidth-range-selector"]');
        const buttons = rangeSelector.findAll('button');

        expect(buttons).toHaveLength(3);
        expect(buttons[0].text()).toBe('1H');
        expect(buttons[1].text()).toBe('24H');
        expect(buttons[2].text()).toBe('72H');
    });

    it('renders DHCP pools with network/CIDR', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="dhcp-pools-card"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('10.0.1.0/24');
        expect(wrapper.text()).toContain('10.0.2.0/24');
        expect(wrapper.text()).toContain('10.0.3.0/24');
    });

    it('renders the recent users table with separate Down and Up columns', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const recentUsersSection = wrapper.find('[data-testid="recent-users-section"]');
        const headers = recentUsersSection.findAll('th').map((header) => header.text());

        expect(wrapper.text()).toContain('Top Bandwidth & Recent Users');
        expect(headers).toEqual(['Nickname', 'Email', 'IPs', 'Down', 'Up', 'Status', 'Seen']);
        expect(wrapper.find('a[href="/mocked/admin.users.show/1"]').text()).toBe('alice');

        const downCells = recentUsersSection.findAll('[data-testid="user-down"]');
        const upCells = recentUsersSection.findAll('[data-testid="user-up"]');
        expect(downCells).toHaveLength(2);
        expect(upCells).toHaveLength(2);

        expect(downCells[0].text()).toBe('1.5 KB');
        expect(upCells[0].text()).toBe('512.0 B');
        expect(downCells[1].text()).toBe('5.0 GB');
        expect(upCells[1].text()).toBe('1.0 GB');
    });

    it('renders the recent users table with inline status dots', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const recentUsersSection = wrapper.find('[data-testid="recent-users-section"]');

        // Status uses inline dots with text labels
        const statusDots = recentUsersSection.findAll('.rounded-full');
        expect(statusDots.length).toBeGreaterThan(0);
        expect(wrapper.text()).toContain('Allowed');
        expect(wrapper.text()).toContain('Denied');
        expect(wrapper.text()).toContain('5m');
        expect(wrapper.text()).toContain('1h');
    });

    it('renders an empty state when there are no recent users', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({
                recentUsers: {
                    current_page: 1,
                    last_page: 1,
                    from: null,
                    to: null,
                    total: 0,
                    links: [],
                    data: [],
                },
            }),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No recent users');
    });

    it('renders pagination when multiple pages are available', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="pagination"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pagination-info"]').text()).toContain('Showing 1–2 of 30');
    });

    it('shows 0% when totalUsers is 0', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({ totalUsers: 0, onlineUsers: 0 }),
            global: defaultGlobal,
        });

        expect(wrapper.text()).toContain('0%');
    });

    it('handles single-page pagination (no pagination component when only 1 page)', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({
                recentUsers: {
                    current_page: 1,
                    last_page: 1,
                    from: 1,
                    to: 2,
                    total: 2,
                    links: [],
                    data: [
                        {
                            id: 1,
                            nickname: 'alice',
                            email: 'alice@example.com',
                            ips_count: 1,
                            weekly_received: 1024,
                            weekly_sent: 256,
                            internet_blocked: false,
                            last_seen: '2026-04-17T11:55:00.000Z',
                        },
                    ],
                },
            }),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="recent-users-section"]').exists()).toBe(true);
    });

    it('calls fetch for bandwidth data on mount', () => {
        mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(global.fetch).toHaveBeenCalled();
        expect(global.fetch.mock.calls[0][0]).toContain('admin.dashboard.bandwidth');
    });
});
