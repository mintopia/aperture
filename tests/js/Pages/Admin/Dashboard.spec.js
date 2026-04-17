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

vi.stubGlobal('route', (name, param) => (param ? `/mocked/${name}/${param}` : `/mocked/${name}`));

describe('Dashboard', () => {
    const makeProps = (overrides = {}) => ({
        totalUsers: 128,
        onlineUsers: 42,
        activeIps: 89,
        blockedUsers: 3,
        dhcpPools: [
            { name: 'Users', used: 89, total: 200, utilisation: 0.445 },
            { name: 'Infrastructure', used: 12, total: 50, utilisation: 0.24 },
            { name: 'Guest', used: 45, total: 50, utilisation: 0.9 },
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
                    total_bandwidth: 1536,
                    blocked: false,
                    last_seen: '2026-04-17T11:55:00.000Z',
                },
                {
                    id: 2,
                    nickname: 'bob',
                    email: 'bob@example.com',
                    ips_count: 1,
                    total_bandwidth: 5368709120,
                    blocked: true,
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
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders Dashboard title and removes legacy reset UI', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Dashboard');
        expect(wrapper.find('[data-testid="reset-button"]').exists()).toBe(false);
    });

    it('renders the four stat cards with the correct labels and values', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const cards = wrapper.findAll('[data-testid="stat-card"]');

        expect(cards).toHaveLength(4);
        expect(wrapper.text()).toContain('ONLINE NOW');
        expect(wrapper.text()).toContain('42');
        expect(wrapper.text()).toContain('TOTAL USERS');
        expect(wrapper.text()).toContain('128');
        expect(wrapper.text()).toContain('IPS ACTIVE');
        expect(wrapper.text()).toContain('89');
        expect(wrapper.text()).toContain('BLOCKED');
        expect(wrapper.text()).toContain('3');
    });

    it('renders online now hero card details including dot, total, and percentage', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        expect(wrapper.find('[data-testid="stat-label-dot"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('of 128');
        expect(wrapper.text()).toContain('33%');
    });

    it('renders deferred loading fallbacks when deferred props are not yet available', () => {
        deferredReady = false;

        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        expect(wrapper.find('[data-testid="dhcp-pools-loading"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="recent-users-loading"]').exists()).toBe(true);
    });

    it('renders DHCP pools and the port errors placeholder card', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        expect(wrapper.find('[data-testid="dhcp-pools-card"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('Infrastructure');
        expect(wrapper.text()).toContain('Guest');
        expect(wrapper.find('[data-testid="port-errors-card"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No error data available');
    });

    it('renders the recent users table with columns, linked nicknames, and status pills', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const headers = wrapper.findAll('th').map((header) => header.text());

        expect(wrapper.text()).toContain('TOP BANDWIDTH & RECENT USERS');
        expect(headers).toEqual(['Nickname', 'Email', 'IPs', 'Bandwidth', 'Status', 'Seen']);
        expect(wrapper.find('a[href="/mocked/admin.users.show/1"]').text()).toBe('alice');
        expect(wrapper.text()).toContain('1.5 KB');
        expect(wrapper.text()).toContain('5.0 GB');
        expect(wrapper.findAll('[data-testid="status-pill"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Active');
        expect(wrapper.text()).toContain('Blocked');
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
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No recent users');
    });

    it('renders pagination when multiple pages are available', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        expect(wrapper.find('[data-testid="pagination"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pagination-info"]').text()).toContain('Showing 1–2 of 30');
    });
});
