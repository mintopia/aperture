import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Index from '@/Pages/Admin/Users/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

vi.stubGlobal('route', (name, param) => (param ? `/mocked/${name}/${param}` : `/mocked/${name}`));

const routeMock = (name, param) => (param ? `/mocked/${name}/${param}` : `/mocked/${name}`);

const defaultGlobal = {
    stubs: {
        AdminLayout: { template: '<div><slot /></div>' },
    },
    mocks: {
        route: routeMock,
    },
};

describe('Users Index', () => {
    const makeProps = (overrides = {}) => ({
        users: {
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
                    ips: ['10.0.0.1', '10.0.0.2'],
                    weekly_received: 2048,
                    weekly_sent: 512,
                    internet_blocked: false,
                },
                {
                    id: 2,
                    nickname: 'bob',
                    email: 'bob@example.com',
                    ips: ['10.0.0.3'],
                    weekly_received: 5368709120,
                    weekly_sent: 1073741824,
                    internet_blocked: true,
                },
            ],
        },
        filters: {},
        ...overrides,
    });

    it('renders the Users page title', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Users');
    });

    it('renders user summary strip with total, active, and blocked counts', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="users-summary"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="summary-total-value"]').text()).toBe('2');
        expect(wrapper.find('[data-testid="summary-active-value"]').text()).toBe('1');
        expect(wrapper.find('[data-testid="summary-blocked-value"]').text()).toBe('1');
    });

    it('renders bandwidth column with Down/Up labels and separate values', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const bandwidthCells = wrapper.findAll('[data-testid="user-bandwidth"]');
        expect(bandwidthCells).toHaveLength(2);

        const aliceBandwidth = bandwidthCells[0].text();
        expect(aliceBandwidth).toContain('Down');
        expect(aliceBandwidth).toContain('Up');
        expect(aliceBandwidth).toContain('2.0 KB');
        expect(aliceBandwidth).toContain('512.0 B');

        const bobBandwidth = bandwidthCells[1].text();
        expect(bobBandwidth).toContain('Down');
        expect(bobBandwidth).toContain('Up');
        expect(bobBandwidth).toContain('5.0 GB');
        expect(bobBandwidth).toContain('1.0 GB');
    });

    it('renders status dots for active and blocked users', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const statusCells = wrapper.findAll('[data-testid="user-status"]');
        expect(statusCells).toHaveLength(2);
        expect(statusCells[0].text()).toContain('Allowed');
        expect(statusCells[1].text()).toContain('Denied');
    });

    it('renders user nicknames and emails', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="user-nickname"]').text()).toBe('alice');
        const emails = wrapper.findAll('[data-testid="user-email"]');
        expect(emails[0].text()).toBe('alice@example.com');
        expect(emails[1].text()).toBe('bob@example.com');
    });

    it('renders IP counts', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const ipsCells = wrapper.findAll('[data-testid="user-ips-count"]');
        expect(ipsCells[0].text()).toBe('2');
        expect(ipsCells[1].text()).toBe('1');
    });

    it('hides summary strip when no users', () => {
        const wrapper = mount(Index, {
            props: makeProps({
                users: {
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

        expect(wrapper.find('[data-testid="users-summary"]').exists()).toBe(false);
    });

    it('renders table headers including Bandwidth (7d)', () => {
        const wrapper = mount(Index, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const headers = wrapper.findAll('th').map((th) => th.text());
        expect(headers).toContain('Bandwidth (7d)');
        expect(headers).toContain('Status');
    });
});
