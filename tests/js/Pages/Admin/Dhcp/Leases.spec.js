import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Leases from '@/Pages/Admin/Dhcp/Leases.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

const mockLeases = [
    { ip: '10.0.0.10', mac: 'AA:BB:CC:DD:EE:01', hostname: 'web-server', expires: '1714000000' },
    { ip: '10.0.0.20', mac: 'AA:BB:CC:DD:EE:02', hostname: 'db-server', expires: '1714100000' },
    { ip: '10.0.1.50', mac: 'AA:BB:CC:DD:EE:03', hostname: 'ap-lobby', expires: '1714200000' },
    { ip: '10.0.1.100', mac: 'AA:BB:CC:DD:EE:04', hostname: '', expires: null },
];

const mockRanges = [
    { name: 'lan', network: '10.0.0.0/24', start: '10.0.0.1', end: '10.0.0.254' },
    { name: 'guest', network: '10.0.1.0/24', start: '10.0.1.1', end: '10.0.1.254' },
];

const defaultProps = {
    leases: mockLeases,
    ranges: mockRanges,
};

function mountLeases(propsOverride = {}) {
    return mount(Leases, {
        props: { ...defaultProps, ...propsOverride },
        global: {
            mocks: {
                route: (...args) => `/mocked/${args[0]}`,
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                teleport: true,
            },
        },
    });
}

describe('Dhcp/Leases', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the page title', () => {
        const wrapper = mountLeases();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('DHCP Leases');
    });

    it('renders the back to ranges link', () => {
        const wrapper = mountLeases();
        expect(wrapper.find('[data-testid="back-to-ranges-link"]').exists()).toBe(true);
    });

    describe('FilterBar integration', () => {
        it('renders the FilterBar component', () => {
            const wrapper = mountLeases();
            expect(wrapper.find('[data-testid="filter-bar"]').exists()).toBe(true);
        });

        it('renders a search input', () => {
            const wrapper = mountLeases();
            expect(wrapper.find('[data-testid="filter-search-input"]').exists()).toBe(true);
        });

        it('renders range filter dropdown when ranges exist', () => {
            const wrapper = mountLeases();
            expect(wrapper.find('[data-testid="filter-select-range"]').exists()).toBe(true);
        });

        it('does not render range filter when no ranges', () => {
            const wrapper = mountLeases({ ranges: [] });
            expect(wrapper.find('[data-testid="filter-select-range"]').exists()).toBe(false);
        });

        it('shows range options with network/CIDR labels', () => {
            const wrapper = mountLeases();
            const select = wrapper.find('[data-testid="filter-select-range"]');
            const options = select.findAll('option');
            expect(options).toHaveLength(3);
            expect(options[0].text()).toBe('All Ranges');
            expect(options[1].text()).toBe('10.0.0.0/24');
            expect(options[2].text()).toBe('10.0.1.0/24');
        });

        it('shows total count', () => {
            const wrapper = mountLeases();
            expect(wrapper.find('[data-testid="filter-count"]').text()).toBe('4 of 4');
        });

        it('search filters leases by IP', async () => {
            const wrapper = mountLeases();
            const input = wrapper.find('[data-testid="filter-search-input"]');

            await input.setValue('10.0.0.10');
            await input.trigger('input');
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(1);
        });

        it('search filters leases by hostname', async () => {
            const wrapper = mountLeases();
            const input = wrapper.find('[data-testid="filter-search-input"]');

            await input.setValue('web-server');
            await input.trigger('input');
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(1);
        });

        it('search filters leases by MAC address', async () => {
            const wrapper = mountLeases();
            const input = wrapper.find('[data-testid="filter-search-input"]');

            await input.setValue('EE:03');
            await input.trigger('input');
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(1);
        });

        it('range filter narrows results', async () => {
            const wrapper = mountLeases();
            const select = wrapper.find('[data-testid="filter-select-range"]');

            await select.setValue('lan');
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(2);
        });

        it('updates count when range filter is applied', async () => {
            const wrapper = mountLeases();
            const select = wrapper.find('[data-testid="filter-select-range"]');

            await select.setValue('guest');
            await wrapper.vm.$nextTick();

            expect(wrapper.find('[data-testid="filter-count"]').text()).toBe('2 of 4');
        });

        it('search + range filter work together', async () => {
            const wrapper = mountLeases();

            await wrapper.find('[data-testid="filter-select-range"]').setValue('lan');
            await wrapper.vm.$nextTick();

            const input = wrapper.find('[data-testid="filter-search-input"]');
            await input.setValue('web');
            await input.trigger('input');
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(1);
        });
    });

    describe('Table display', () => {
        it('renders all lease rows', () => {
            const wrapper = mountLeases();
            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(4);
        });

        it('renders IP, MAC, hostname, and expiry columns', () => {
            const wrapper = mountLeases();
            const headers = wrapper.findAll('th');
            expect(headers.map((h) => h.text().replace(/[↑↓]/, '').trim())).toEqual([
                'IP Address',
                'MAC Address',
                'Hostname',
                'Expires',
            ]);
        });

        it('shows em dash for empty hostname', () => {
            const wrapper = mountLeases();
            expect(wrapper.find('[data-testid="lease-row-3-hostname"]').text()).toBe('—');
        });

        it('shows "Never" for null expiry', () => {
            const wrapper = mountLeases();
            expect(wrapper.find('[data-testid="lease-row-3-expires"]').text()).toBe('Never');
        });

        it('shows empty message when no leases', () => {
            const wrapper = mountLeases({ leases: [] });
            expect(wrapper.find('[data-testid="data-table-empty"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="data-table-empty"]').text()).toBe('No active leases');
        });
    });

    describe('Sorting', () => {
        it('sorts by IP address ascending by default', () => {
            const wrapper = mountLeases();
            const firstIp = wrapper.find('[data-testid="lease-row-0-ip"]');
            expect(firstIp.text()).toBe('10.0.0.10');
        });

        it('toggles sort direction on column click', async () => {
            const wrapper = mountLeases();
            const ipHeader = wrapper.findAll('th')[0];

            await ipHeader.trigger('click');
            await wrapper.vm.$nextTick();

            const firstIp = wrapper.find('[data-testid="lease-row-0-ip"]');
            expect(firstIp.text()).toBe('10.0.1.100');
        });

        it('shows sort indicator on active column', () => {
            const wrapper = mountLeases();
            const ipHeader = wrapper.findAll('th')[0];
            expect(ipHeader.text()).toContain('↑');
        });
    });

    describe('Show more', () => {
        it('shows "Show More" button when more leases exist than display limit', () => {
            const manyLeases = Array.from({ length: 60 }, (_, i) => ({
                ip: `10.0.0.${i + 1}`,
                mac: `AA:BB:CC:DD:EE:${String(i).padStart(2, '0')}`,
                hostname: `host-${i}`,
                expires: '1714000000',
            }));

            const wrapper = mountLeases({ leases: manyLeases, ranges: [] });
            expect(wrapper.find('[data-testid="show-more-button"]').exists()).toBe(true);
        });

        it('hides "Show More" when all leases fit', () => {
            const wrapper = mountLeases();
            expect(wrapper.find('[data-testid="show-more-button"]').exists()).toBe(false);
        });
    });
});
