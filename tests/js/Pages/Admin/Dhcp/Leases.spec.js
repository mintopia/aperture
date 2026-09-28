import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Leases from '@/Pages/Admin/Dhcp/Leases.vue';
import { isIpInPrefix } from '@/utils/dhcp.js';

describe('isIpInPrefix', () => {
    it.each([
        { name: 'address inside the prefix', addr: '2001:db8:1::5', prefix: '2001:db8:1::/64', expected: true },
        { name: 'matches case-insensitively', addr: '2001:DB8:1::5', prefix: '2001:db8:1::/64', expected: true },
        { name: 'address outside the prefix', addr: '2001:db8:2::5', prefix: '2001:db8:1::/64', expected: false },
        {
            name: 'partial hextet match only (10 vs 1)',
            addr: '2001:db8:10::5',
            prefix: '2001:db8:1::/64',
            expected: false,
        },
        {
            name: 'partial hextet match only (1f00 vs 1)',
            addr: '2001:db8:1f00::5',
            prefix: '2001:db8:1::/64',
            expected: false,
        },
        { name: 'null address', addr: null, prefix: '2001:db8:1::/64', expected: false },
        { name: 'null prefix', addr: '2001:db8:1::5', prefix: null, expected: false },
        { name: 'empty inputs', addr: '', prefix: '', expected: false },
        { name: 'prefix with no network portion', addr: '2001:db8:1::5', prefix: '::/0', expected: false },
        {
            name: 'zero-compressed prefix rejects a mismatched address',
            addr: '2001:db8:1::5',
            prefix: '2001:db8::/64',
            expected: false,
        },
        {
            name: 'zero-compressed prefix rejects a mismatched address (fd00)',
            addr: 'fd00:1::5',
            prefix: 'fd00::/64',
            expected: false,
        },
        {
            name: 'zero-compressed prefix matches an address inside it',
            addr: '2001:db8::5',
            prefix: '2001:db8::/64',
            expected: true,
        },
        {
            name: 'zero-compressed prefix matches an address inside it (fd00)',
            addr: 'fd00::1:5',
            prefix: 'fd00::/64',
            expected: true,
        },
        { name: 'prefix without a length', addr: '2001:db8:1::5', prefix: '2001:db8:1::', expected: false },
        { name: 'invalid prefix length', addr: '2001:db8:1::5', prefix: '2001:db8:1::/129', expected: false },
        {
            name: 'malformed address (invalid hex digit)',
            addr: '2001:db8:zz::5',
            prefix: '2001:db8:1::/64',
            expected: false,
        },
        {
            name: 'malformed address (too many groups)',
            addr: '1:2:3:4:5:6:7:8:9',
            prefix: '2001:db8:1::/64',
            expected: false,
        },
        { name: 'malformed prefix', addr: '2001:db8:1::5', prefix: 'not-a-prefix/64', expected: false },
        { name: 'IPv4 address input', addr: '10.0.0.5', prefix: '2001:db8:1::/64', expected: false },
        { name: 'IPv4 prefix input', addr: '2001:db8:1::5', prefix: '10.0.0.0/24', expected: false },
        {
            name: 'non-hextet-aligned prefix length /63 matches lower half',
            addr: '2001:db8:1:8000::5',
            prefix: '2001:db8:1:8000::/63',
            expected: true,
        },
        {
            name: 'non-hextet-aligned prefix length /63 matches upper half',
            addr: '2001:db8:1:8001::5',
            prefix: '2001:db8:1:8000::/63',
            expected: true,
        },
        {
            name: 'non-hextet-aligned prefix length /63 rejects outside range',
            addr: '2001:db8:1:8002::5',
            prefix: '2001:db8:1:8000::/63',
            expected: false,
        },
    ])('$name', ({ addr, prefix, expected }) => {
        expect(isIpInPrefix(addr, prefix)).toBe(expected);
    });
});

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

// Ranges/leases exercising the prefix-based (IPv6) branch of applyRangeFilter,
// which the IPv4 start/end ranges above never reach.
const prefixRanges = [{ network: '2001:db8:1::/64', start: null, end: null, prefix: '2001:db8:1::/64' }];
const prefixLeases = [
    { ip: '10.0.0.50', mac: 'AA:BB:CC:DD:EE:01', hostname: 'host-v4', expires: '' },
    { ip: '2001:db8:1::5', mac: 'AA:BB:CC:DD:EE:02', hostname: 'host-v6-a', expires: '' },
    { ip: '2001:DB8:1::1F', mac: 'AA:BB:CC:DD:EE:03', hostname: 'host-v6-b', expires: '' },
    { ip: '2001:db8:2::5', mac: 'AA:BB:CC:DD:EE:04', hostname: 'host-v6-other', expires: '' },
];

function mountLeasesAtNetwork(network) {
    const query = network ? '/?network=' + encodeURIComponent(network) : '/';
    window.history.replaceState({}, '', query);
    return mountLeases({ leases: prefixLeases, ranges: prefixRanges });
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
        beforeEach(() => {
            vi.useFakeTimers();
        });

        afterEach(() => {
            vi.useRealTimers();
        });

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
            vi.advanceTimersByTime(300);
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(1);
        });

        it('search filters leases by hostname', async () => {
            const wrapper = mountLeases();
            const input = wrapper.find('[data-testid="filter-search-input"]');

            await input.setValue('web-server');
            vi.advanceTimersByTime(300);
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(1);
        });

        it('search filters leases by MAC address', async () => {
            const wrapper = mountLeases();
            const input = wrapper.find('[data-testid="filter-search-input"]');

            await input.setValue('EE:03');
            vi.advanceTimersByTime(300);
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(1);
        });

        it('range filter narrows results', async () => {
            const wrapper = mountLeases();
            const select = wrapper.find('[data-testid="filter-select-range"]');

            await select.setValue('10.0.0.0/24');
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(2);
        });

        it('updates count when range filter is applied', async () => {
            const wrapper = mountLeases();
            const select = wrapper.find('[data-testid="filter-select-range"]');

            await select.setValue('10.0.1.0/24');
            await wrapper.vm.$nextTick();

            expect(wrapper.find('[data-testid="filter-count"]').text()).toBe('2 of 4');
        });

        it('search + range filter work together', async () => {
            const wrapper = mountLeases();

            await wrapper.find('[data-testid="filter-select-range"]').setValue('10.0.0.0/24');
            await wrapper.vm.$nextTick();

            const input = wrapper.find('[data-testid="filter-search-input"]');
            await input.setValue('web');
            vi.advanceTimersByTime(300);
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

    describe('IP sorting and range membership', () => {
        const ipOrder = (wrapper) =>
            wrapper.findAll('[data-testid^="lease-row-"][data-testid$="-ip"]').map((el) => el.text());

        it('sorts mixed IPv4 and compressed IPv6 numerically', () => {
            const wrapper = mountLeases({
                ranges: [],
                leases: [
                    { ip: '2001:db8::10', mac: 'A', hostname: '', expires: '' },
                    { ip: '10.0.0.10', mac: 'B', hostname: '', expires: '' },
                    { ip: '2001:db8::2', mac: 'C', hostname: '', expires: '' },
                    { ip: '10.0.0.9', mac: 'D', hostname: '', expires: '' },
                    { ip: '::1', mac: 'E', hostname: '', expires: '' },
                ],
            });
            expect(ipOrder(wrapper)).toEqual(['::1', '10.0.0.9', '10.0.0.10', '2001:db8::2', '2001:db8::10']);
        });

        it('reverses the order when sorted descending', async () => {
            const wrapper = mountLeases({
                ranges: [],
                leases: [
                    { ip: '2001:db8::2', mac: 'C', hostname: '', expires: '' },
                    { ip: '2001:db8::10', mac: 'A', hostname: '', expires: '' },
                ],
            });
            await wrapper.findAll('th')[0].trigger('click');
            expect(ipOrder(wrapper)).toEqual(['2001:db8::10', '2001:db8::2']);
        });

        it('applies start/end range membership to compressed IPv6', () => {
            window.history.replaceState({}, '', '/?network=lan6');
            const wrapper = mountLeases({
                ranges: [{ network: 'lan6', start: '2001:db8::2', end: '2001:db8::ff' }],
                leases: [
                    { ip: '2001:db8::10', mac: 'A', hostname: '', expires: '' },
                    { ip: '2001:db8::100', mac: 'B', hostname: '', expires: '' },
                ],
            });
            expect(ipOrder(wrapper)).toEqual(['2001:db8::10']);
        });
    });

    describe('Expiry formatting', () => {
        it('formats epoch expiry via the shared en-GB date format', () => {
            const wrapper = mountLeases();
            expect(wrapper.find('[data-testid="lease-row-0-expires"]').text()).toMatch(
                /^\d{1,2} Apr 2024, \d{2}:\d{2}$/,
            );
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

    describe('URL-seeded range filter (?network=)', () => {
        afterEach(() => {
            window.history.replaceState({}, '', '/');
        });

        it('shows all leases when no network is in the URL', () => {
            const wrapper = mountLeasesAtNetwork(null);
            expect(wrapper.findAll('[data-testid="data-table-row"]')).toHaveLength(4);
        });

        it('filters leases by prefix for an IPv6 range without start/end', () => {
            const wrapper = mountLeasesAtNetwork('2001:db8:1::/64');
            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(2);
            expect(wrapper.text()).toContain('host-v6-a');
            expect(wrapper.text()).toContain('host-v6-b');
            expect(wrapper.text()).not.toContain('host-v6-other');
            expect(wrapper.text()).not.toContain('host-v4');
        });

        it('shows all leases when the URL-seeded range does not exist', () => {
            const wrapper = mountLeasesAtNetwork('192.168.99.0/24');
            expect(wrapper.findAll('[data-testid="data-table-row"]')).toHaveLength(4);
        });

        it('filters leases by lexicographic start/end for an IPv6 range', async () => {
            const wrapper = mountLeases({
                leases: [
                    { ip: '2001:db8:1::5', mac: 'AA:BB:CC:DD:EE:01', hostname: 'in-range', expires: '' },
                    { ip: '2001:db8:1::6', mac: 'AA:BB:CC:DD:EE:02', hostname: 'out-of-range', expires: '' },
                ],
                ranges: [{ network: '2001:db8:1::/64', start: '2001:db8:1::5', end: '2001:db8:1::5' }],
            });

            await wrapper.find('[data-testid="filter-select-range"]').setValue('2001:db8:1::/64');
            await wrapper.vm.$nextTick();

            const rows = wrapper.findAll('[data-testid="data-table-row"]');
            expect(rows).toHaveLength(1);
            expect(wrapper.text()).toContain('in-range');
        });
    });
});
